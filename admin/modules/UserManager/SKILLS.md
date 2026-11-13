# UserManager — Skills Reference

## Overview
User account management with role-based access control supporting three tiers: superadmin, admin, and staff. Handles user CRUD, password management, status toggling, and access privilege configuration.

## Capabilities
- User CRUD operations (create, read, update, delete)
- Role-based access control (superadmin, admin, staff)
- Password management and reset
- Access privilege matrix (superadmin only)
- User status toggling (active/inactive)
- Email-based authentication

## API Endpoints
- `action=list` — List all users
- `action=get` — Get user details
- `action=create` — Create new user
- `action=update` — Update user info
- `action=delete` — Delete a user
- `action=change_pass` — Change password
- `action=toggle` — Toggle user active/inactive
- `action=reset_pass` — Reset user password
- `action=get_permissions` — Get permission matrix
- `action=save_permissions` — Save permission matrix

## Data Storage
- User data in site auth/user files

## Dependencies
- None (foundational module)

## Common Tasks
1. **Create a user**: Enter email, name, role, password — save
2. **Change role**: Update user with new role assignment
3. **Reset password**: Use reset_pass for forgotten passwords
4. **Deactivate user**: Toggle status to inactive
5. **Configure permissions**: Edit privilege matrix for role-based access
