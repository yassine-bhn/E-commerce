# Account Security Tab

## Goal

Place the logged-in user’s password-change form in a dedicated `Sécurité` tab alongside `Mes commandes` and `Mes adresses`.

## Design

The account page keeps its existing two-column layout and adds a third tab named `Sécurité` to the existing tab list. The `Sécurité` tab renders the existing password-change form and its current endpoint, validation, loading state, and toast behavior.

The profile card remains responsible for name, phone, account type, profile saving, and logout. Password fields are removed from the always-visible profile card so they appear only after the user selects `Sécurité`.

No backend or API changes are required. The existing authenticated password endpoint remains the data boundary.

## Validation

- TypeScript compilation passes.
- Targeted ESLint passes for the modified account components.
- Browser verification confirms all three tabs switch correctly and the password form appears only under `Sécurité`.
