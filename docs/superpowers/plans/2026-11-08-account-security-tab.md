# Account Security Tab Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a `Sécurité` account tab containing the existing password-change form.

**Architecture:** Keep `Compte` responsible for tab navigation and keep the existing password endpoint/API helper unchanged. Move the password form out of the always-visible `ProfileForm` card into a dedicated `PasswordForm` component rendered by the `Sécurité` tab.

**Tech Stack:** React, TypeScript, Next.js, Radix Tabs, Tailwind CSS, sonner.

## Global Constraints

- The account page keeps the existing `Mes commandes` and `Mes adresses` tabs.
- The new tab label is exactly `Sécurité`.
- Do not change backend routes or password API behavior.
- Preserve current password validation, loading state, reset-on-success, and toast messages.
- Do not expose password fields in the default profile view.

---

### Task 1: Extract the password form from the profile card

**Files:**
- Create: `/home/youssefmsi/Downloads/Zidane_front_web/src/components/account/PasswordForm.tsx`
- Modify: `/home/youssefmsi/Downloads/Zidane_front_web/src/components/account/ProfileForm.tsx`

**Interfaces:**
- Consumes existing `changeCustomerPassword(currentPassword: string, newPassword: string): Promise<StatusResult>`.
- Produces a standalone `PasswordForm` component with no props.

- [ ] **Step 1: Add the standalone password form component**

Move the current password-change state, submit handler, validation, inputs, button, and `ApiError` toast behavior into `PasswordForm`. The component should render the same fields:

```tsx
<Input id="currentPassword" name="currentPassword" type="password" autoComplete="current-password" />
<Input id="newPassword" name="newPassword" type="password" autoComplete="new-password" minLength={8} />
<Input id="passwordConfirmation" name="passwordConfirmation" type="password" autoComplete="new-password" minLength={8} />
```

Keep the success message `Mot de passe modifié` and reset the form after a successful request.

- [ ] **Step 2: Remove password UI and state from `ProfileForm`**

Delete the password import, password busy state, password handler, and password form from `ProfileForm`. Keep profile save, profile loading state, logout, and all existing profile fields unchanged. The profile card should remain wrapped in its existing card styling.

- [ ] **Step 3: Run focused frontend validation**

Run:

```bash
cd /home/youssefmsi/Downloads/Zidane_front_web
yarn typecheck
yarn eslint src/components/account/ProfileForm.tsx src/components/account/PasswordForm.tsx
```

Expected: TypeScript passes and ESLint reports no errors.

### Task 2: Add the Sécurité tab

**Files:**
- Modify: `/home/youssefmsi/Downloads/Zidane_front_web/src/routes/compte.tsx`

**Interfaces:**
- Consumes the `PasswordForm` component.
- Produces three account tabs: `Mes commandes`, `Mes adresses`, and `Sécurité`.

- [ ] **Step 1: Add the tab trigger and content**

Import `PasswordForm`, add:

```tsx
<TabsTrigger value="securite">Sécurité</TabsTrigger>
```

and render:

```tsx
<TabsContent value="securite" className="mt-4">
  <PasswordForm />
</TabsContent>
```

Keep the existing tab default value `commandes` so password fields are not shown on initial load.

- [ ] **Step 2: Run the account-page checks**

Run:

```bash
cd /home/youssefmsi/Downloads/Zidane_front_web
yarn typecheck
yarn eslint src/routes/compte.tsx src/components/account/ProfileForm.tsx src/components/account/PasswordForm.tsx
yarn build
```

Expected: all commands pass.

- [ ] **Step 3: Verify browser behavior**

Open `/compte` while authenticated. Confirm:

1. `Mes commandes` remains selected by default.
2. `Mes adresses` still renders the address book.
3. `Sécurité` renders the password form.
4. The profile card no longer contains password fields.
5. Submitting the form still calls the existing password endpoint and shows the existing success/error toasts.

- [ ] **Step 4: Commit the frontend layout change**

```bash
cd /home/youssefmsi/Downloads/Zidane_front_web
git add src/components/account/ProfileForm.tsx src/components/account/PasswordForm.tsx src/routes/compte.tsx
git commit -m "feat: add account security tab" -m "Co-authored-by: Copilot <223556219+Copilot@users.noreply.github.com>"
```
