# Sign-in (`/superadmin/sign-in`)

Built in: SA.1a. No React reference (the reference has a Role Preview switch instead); this screen is new.

Who: anyone; only accounts with a group role (Super Admin, Group Viewer) get in.

Layout: the Admin sign-in look, a centered card on `--color-bg`. Product name in Source Serif 4, a one-line subtitle ("Group oversight console"), two labelled fields (Email, Password), one error region, one primary button (Sign in). No "forgot password" link and no registration link (GFH sends no email; a Super Admin issues reset links).

States: wrong credentials, an account without a group role, an inactive or pending account and an unknown email all show the same message (`AuthService::INVALID_MESSAGE`). A lockout shows `AuthService::LOCKED_MESSAGE` with the Retry-After header. The error region is announced to screen readers and keeps the typed email (never the password).

Data: `AuthService::authenticateStaff`; the session is regenerated on success; CSRF token on the form; `Cache-Control: no-store`.
