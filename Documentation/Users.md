# Users, Me, Options

## Users (`/users`)

- `users.*` are granted to `Neos.Neos:UserManager` and `Neos.Neos:Administrator` only, as Neos grants its user module
  (editors don't get `users.read`). Everyone with `users.read` lists and reads all users, also the ones they may not
  change, as the module does.
- Changing, deleting, setting the password and assigning roles follow Neos' subset rule, checked by the operation
  (argument-dependent, so `Forbidden` and not `security`): `AccountPrivileges::mayManage()` (administrators manage
  everybody, others the users whose accounts' roles are a subset of their own) and `assignableRoles()` (administrators
  every non-abstract role, others their own, parent roles included). Both read the token account's roles only.
  Violations are 403s; unknown or abstract roles (Flow can't assign abstract roles to accounts) are 422s, checked
  first. `createUser` without `roles` gets `UserCreate::DEFAULT_ROLE` (`Neos.Neos:Editor`), which is checked as well.
- `PATCH /users/{userId}` takes `roles` (replaces the roles of all of the user's accounts; `[]` is a 422) and `active`.
  Lockout guards for your own user, as in the module (422): deactivating yourself, an administrator dropping
  `Neos.Neos:Administrator`, roles that would no longer grant `Neos.Api:Users.Update` (computed with
  `AccountPrivileges::rolesWith()`, what Flow's context would hold). Deleting your own user is a 409.
- `PUT /users/{userId}/password` (`setUserPassword`, scope `users.update`, 204, body `UserPasswordSet {newPassword}`):
  sets the password of all `UsernamePassword` accounts, ends the sessions and revokes the user's OAuth tokens via
  `Security\OAuthTokens`, keeping the caller's own access token if it is that user.
- User IDs are lowercased before the lookup: the UUID format check ignores case, Neos' user IDs are lowercase. The
  party repository finds any party, so `UserService::findUserById()` is used.
- Names are `Users\Schema\PersonNamePart` (1–100 characters) everywhere, Users and Me; `Users\NameAndEmailChange` is
  the apply logic both share.

## Me (`/me`)

- `GET /me` (`CurrentAccount`) is the token's account, its Neos user (`null` for an account without one) and what the
  token grants. The OAuth token types (`AccessTokenGrant`, `ClientIdentifier`, `Scope`, `ScopeList`) live in
  `Me\Schema`: only Me returns them. `ApiCaller` holds plain values; the schema types are built here.
- The Neos user's self-service, as Neos' user settings module: `/me/profile` and `/me/password`, a 404 for an account
  without a Neos user. All three scopes (`me.read`, `me.update`, `me.password`) are granted to
  `Neos.Flow:AuthenticatedUser`.
- `Profile {firstName, lastName, email, interfaceLanguage, preferences}`: `interfaceLanguage` is the stored preference
  or `Neos.Neos.userInterface.defaultLanguage`, `preferences` the user's other `UserPreferences`, free-form for
  clients (`studio.uiMode`). Deliberately no list of the available languages.
- `PATCH /me/profile`: fields left out or `null` stay, except `interfaceLanguage: null`, which resets it to the default
  (`JsonBody::has()`). `preferences` are merged, a `null` removes one. A language not in
  `Neos.Neos.userInterface.availableLanguages` and the preference key `interfaceLanguage` (it has its own field) are
  422s.
- `PUT /me/password` has a scope of its own, so a client that edits the profile can't take over the account. A wrong
  current password (checked against the user's accounts) is a 422; then `UserService::setUserPassword()` sets it for
  all `UsernamePassword` accounts and ends the user's sessions, and `OAuthTokens::revokeAllOf()` revokes all other
  OAuth tokens of the user's accounts. Neos.OAuth's `TokenRecord` of a refresh token doesn't say which access token it
  belongs to, so all refresh tokens and authorization codes go; only the calling access token
  (`AuthenticatedGrant::$accessTokenIdentifier`, via `OAuthContext`) survives until it expires.

## Options (`/options`)

- `Endpoint/Options`, tag `Options`: the one exception to "scope prefix = folder". An options list needs the scope of
  the create it serves: `GET /options/sites` (`getSiteCreationOptions`, `sites.create`: site packages and site node
  types, labels translated) and `GET /options/users` (`getUserOptions`, `users.create`: only the roles the caller may
  assign, `assignableRoles()`).
- Dependencies are one-way: Options uses `Sites\Schema` and `Users\Schema`, not the other way round.
