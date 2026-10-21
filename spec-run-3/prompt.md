# Prompt (verbatim archive)

## Turn 1 (delivered truncated by the client at the end of the last bullet)

```
/Spec
for the dashboard admin the admin should be able to manage :
- product(already implemented)
- categories (add a new category, add a sub category for an existing one, delete a category, hide a category)
- the users: delete a user, edit the role of a user(ROLE_USER, ROLE_SUPER_ADMIN), see the orders of a user.
- change the banner images (the banner should contain a dynamic number of images related to the images upload
```

> Truncation note (added by the orchestrator, not part of the user's words): the last line was cut mid-word at
> `upload`. The same clause was visible earlier in the session as
> `(the banner should contain a dynamic number of images related to the images uploaded by the admin in the dashboard )`,
> which is how it is carried into the spec. Flagged to the user twice; the user chose to proceed rather than retype it.

## Turn 2 (user clarification)

```
yes the frontend app is a separated one i just need to prepare the endpoints for the frontend only
implement the admin dashboard managment crud
```

## Consolidated intent

Backend API only. No admin UI work in this repository — the consuming frontend is a separate app.
