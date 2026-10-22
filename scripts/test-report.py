#!/usr/bin/env python3
"""Consolidated PHPUnit failure report.

Reads the JUnit XML that `phpunit --log-junit` writes for every suite, plus the
status manifest produced by scripts/run-tests.sh, and prints one entry per test
that failed. On GitHub Actions it also appends a Markdown summary to
$GITHUB_STEP_SUMMARY and emits ::error annotations, so a red run points at the
failing tests instead of only turning the job red.

    python3 scripts/test-report.py --reports var/test-reports --status var/test-reports/status.tsv

The exit code is authoritative, not the XML: phpunit.dist.xml sets
failOnDeprecation/Notice/Warning, so a suite can exit non-zero while every
<testcase> in its report passed. That case is reported explicitly as a broken
suite, with the log file to read.

Exit status: 0 when every suite is green, 1 otherwise.
"""

from __future__ import annotations

import argparse
import os
import sys
import xml.etree.ElementTree as ET
from dataclasses import dataclass, field

# JUnit reports are written inside the container, so they carry /app/ prefixes.
CONTAINER_ROOT = "/app/"
# <failure> is an assertion, <error> an exception; <warning> exists in some reporters.
PROBLEM_TAGS = ("failure", "error", "warning")
MAX_MESSAGE = 240
MAX_MARKDOWN_ENTRIES = 100


@dataclass
class Failure:
    suite: str
    name: str
    kind: str
    file: str = ""
    line: int = 0
    message: str = ""


@dataclass
class SuiteReport:
    name: str
    exit_code: int
    log: str = ""
    tests: int = 0
    failures: int = 0
    errors: int = 0
    skipped: int = 0
    problems: list[Failure] = field(default_factory=list)
    note: str = ""

    @property
    def failed(self) -> bool:
        return self.exit_code != 0


def as_int(value: str | None) -> int:
    try:
        return int(value or 0)
    except ValueError:
        return 0


def plural(count: int, singular: str) -> str:
    return f"{count} {singular}" if count == 1 else f"{count} {singular}s"


def repo_relative(path: str) -> str:
    """Turn an in-container absolute path into a repository-relative one."""
    if path.startswith(CONTAINER_ROOT):
        return path[len(CONTAINER_ROOT):]
    if path.startswith("/app"):
        return path[len("/app"):].lstrip("/")
    return path


def first_message(text: str | None) -> str:
    """Extract the assertion message, dropping PHPUnit's "Class::test" header line."""
    if not text:
        return ""
    lines = [line.strip() for line in text.strip().splitlines()]
    if lines and "::" in lines[0]:
        lines = lines[1:]
    for line in lines:
        if line:
            return line[:MAX_MESSAGE]
    return ""


def read_status(path: str) -> list[tuple[str, int, str]]:
    """Parse the "suite<TAB>exit-code<TAB>log" manifest written by run-tests.sh."""
    rows: list[tuple[str, int, str]] = []
    with open(path, encoding="utf-8") as handle:
        for raw in handle:
            line = raw.rstrip("\n")
            if not line.strip() or line.lstrip().startswith("#"):
                continue
            parts = line.split("\t")
            if len(parts) < 2:
                continue
            suite = parts[0].strip()
            try:
                code = int(parts[1].strip())
            except ValueError:
                # An unreadable status is a broken suite, never a green one.
                code = 1
            log = parts[2].strip() if len(parts) > 2 else ""
            rows.append((suite, code if suite else 1, log))
    return rows


def build_report(suite: str, exit_code: int, log: str, xml_path: str) -> SuiteReport:
    report = SuiteReport(name=suite, exit_code=exit_code, log=log)

    if not os.path.exists(xml_path):
        if exit_code != 0:
            report.problems.append(
                Failure(
                    suite=suite,
                    name=f"<{suite} suite>",
                    kind="suite",
                    message="the suite exited before writing a JUnit report (crash, missing service or bad bootstrap)",
                )
            )
        else:
            report.note = "no JUnit report"
        return report

    try:
        root = ET.parse(xml_path).getroot()
    except (OSError, ET.ParseError) as error:
        report.problems.append(
            Failure(
                suite=suite,
                name=f"<{suite} suite>",
                kind="suite",
                message=f"unreadable JUnit report: {error}",
            )
        )
        return report

    # Only the top-level suites are summed: a data-provider suite nests its own
    # <testsuite> whose counts are already rolled up into the parent.
    top_level = [root] if root.tag == "testsuite" else root.findall("testsuite")
    for node in top_level:
        report.tests += as_int(node.get("tests"))
        report.failures += as_int(node.get("failures"))
        report.errors += as_int(node.get("errors"))
        report.skipped += as_int(node.get("skipped"))

    for case in root.iter("testcase"):
        problem = next((child for child in case if child.tag in PROBLEM_TAGS), None)
        if problem is None:
            continue
        class_name = case.get("class") or case.get("classname") or ""
        test_name = case.get("name") or "<unknown test>"
        report.problems.append(
            Failure(
                suite=suite,
                name=f"{class_name}::{test_name}" if class_name else test_name,
                kind=problem.tag,
                file=repo_relative(case.get("file") or ""),
                line=as_int(case.get("line")),
                message=first_message(problem.text),
            )
        )

    # failOnDeprecation/Notice/Warning and risky tests turn the suite red without
    # producing a failing <testcase>: never let that look green.
    if exit_code != 0 and not report.problems:
        report.problems.append(
            Failure(
                suite=suite,
                name=f"<{suite} suite>",
                kind="suite",
                message="the suite failed without a failing test case (deprecation, notice, warning or risky test)",
            )
        )

    return report


def totals(reports: list[SuiteReport]) -> tuple[int, int, int]:
    tests = sum(report.tests for report in reports)
    failing = sum(len(report.problems) for report in reports)
    return len(reports), tests, failing


def render_text(reports: list[SuiteReport]) -> str:
    suites, tests, failing = totals(reports)
    lines = [f"Test report: {plural(suites, 'suite')}, {plural(tests, 'test')}, {plural(failing, 'failure')}", ""]

    if failing:
        lines.append("Failing tests")
        lines.append("-------------")
        for report in reports:
            for problem in report.problems:
                lines.append(f"{problem.kind.upper():<8}{problem.suite:<14}{problem.name}")
                location = f"{problem.file}:{problem.line}" if problem.file and problem.line else problem.file
                if location:
                    lines.append(f"{'':<22}{location}")
                if problem.message:
                    lines.append(f"{'':<22}{problem.message}")
                if problem.kind == "suite" and report.log:
                    lines.append(f"{'':<22}log: {report.log}")
                lines.append("")
    else:
        lines.append("Every test passed.")
        lines.append("")

    lines.append("Suites")
    lines.append("------")
    for report in reports:
        status = "FAILED" if report.failed else "ok"
        detail = plural(report.tests, "test")
        if report.failures or report.errors:
            detail += f", {plural(report.failures, 'failure')}, {plural(report.errors, 'error')}"
        if report.skipped:
            detail += f", {plural(report.skipped, 'skipped test')}"
        if report.note:
            detail += f" ({report.note})"
        lines.append(f"  {report.name:<14}{status:<8}{detail}")

    return "\n".join(lines)


def markdown_code(text: str) -> str:
    """Keep a message safe inside an inline code span."""
    return text.replace("`", "'").replace("\n", " ")


def render_markdown(reports: list[SuiteReport]) -> str:
    suites, tests, failing = totals(reports)
    lines = ["## Test report", ""]

    if failing:
        lines.append(f"**{plural(failing, 'failure')}** across {plural(suites, 'suite')}, {plural(tests, 'test')}.")
    else:
        lines.append(f"All {plural(tests, 'test')} passed across {plural(suites, 'suite')}.")

    lines += ["", "| Suite | Result | Tests | Failures | Errors | Skipped |", "|---|---|---|---|---|---|"]
    for report in reports:
        result = "**failed**" if report.failed else "ok"
        lines.append(
            f"| {report.name} | {result} | {report.tests} | {report.failures} | {report.errors} | {report.skipped} |"
        )

    if failing:
        lines += ["", "### Failing tests", ""]
        entries = [(report, problem) for report in reports for problem in report.problems]
        for index, (report, problem) in enumerate(entries[:MAX_MARKDOWN_ENTRIES], start=1):
            location = f"{problem.file}:{problem.line}" if problem.file and problem.line else problem.file
            suffix = f" — `{location}`" if location else ""
            message = f"<br>{markdown_code(problem.message)}" if problem.message else ""
            if problem.kind == "suite" and report.log:
                message += f"<br>log: `{report.log}`"
            lines.append(f"{index}. **{report.name}** — `{problem.name}`{suffix}{message}")
        hidden = len(entries) - MAX_MARKDOWN_ENTRIES
        if hidden > 0:
            lines += ["", f"… and {hidden} more in the `phpunit-reports` artifact."]

    return "\n".join(lines)


def github_escape(text: str) -> str:
    return text.replace("%", "%25").replace("\r", "%0D").replace("\n", "%0A")


def emit_annotations(reports: list[SuiteReport]) -> None:
    for report in reports:
        for problem in report.problems:
            properties = []
            if problem.file:
                properties.append(f"file={problem.file}")
                if problem.line:
                    properties.append(f"line={problem.line}")
            # Commas and colons would split the command's property list.
            title = " ".join(f"{report.name}: {problem.name}".replace(",", " ").replace(":", " ").split())
            properties.append(f"title={title}")
            print(f"::error {','.join(properties)}::{github_escape(problem.message or problem.kind)}")


def main(argv: list[str]) -> int:
    parser = argparse.ArgumentParser(description="Report the failing PHPUnit tests from JUnit XML.")
    parser.add_argument("--reports", default="var/test-reports", help="directory holding <suite>.xml (default: var/test-reports)")
    parser.add_argument("--status", default="", help="status manifest written by scripts/run-tests.sh (default: <reports>/status.tsv)")
    parser.add_argument("--markdown", action="store_true", help="print the Markdown report instead of the plain-text one")
    args = parser.parse_args(argv)

    status_path = args.status or os.path.join(args.reports, "status.tsv")
    if not os.path.exists(status_path):
        print(f"test-report: no status manifest at {status_path} — run scripts/run-tests.sh first", file=sys.stderr)
        return 2

    rows = read_status(status_path)
    if not rows:
        print(f"test-report: {status_path} lists no suite", file=sys.stderr)
        return 2

    reports = [
        build_report(suite, code, log, os.path.join(args.reports, f"{suite.lower()}.xml"))
        for suite, code, log in rows
    ]

    print(render_markdown(reports) if args.markdown else render_text(reports))

    summary_path = os.environ.get("GITHUB_STEP_SUMMARY")
    if summary_path:
        with open(summary_path, "a", encoding="utf-8") as summary:
            summary.write(render_markdown(reports) + "\n")

    if os.environ.get("GITHUB_ACTIONS") == "true":
        emit_annotations(reports)

    return 1 if any(report.failed for report in reports) else 0


if __name__ == "__main__":
    sys.exit(main(sys.argv[1:]))
