# Superadmin Password Seed

## Goal

Ensure the seeded account `superadmin@zidane.tn` can authenticate with the requested
password `password123` on fresh database setup.

## Design

The superadmin row is created by `Version20261017000004`, while the
`password_hash` column is created by the subsequent `Version20261017000005`.
Therefore, the password seed belongs in `Version20261017000005`, immediately
after the column is added. The migration will write a bcrypt hash, never the
plaintext password, and will target the account by its unique email.

This preserves migration ordering and makes fresh installs reproducible. It does
not retroactively change databases where `Version20261017000005` has already
run; those databases require a separate controlled migration or rebuild.

## Validation

Verify the migration diff contains only the schema addition and targeted
superadmin hash update, then run the repository's applicable PHP syntax check.
