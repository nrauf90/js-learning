# Admin Panel Setup & Testing Guide

## Initial Setup

### 1. Run Database Migration

Add the `is_admin` field to users table:

```bash
cd backend
php artisan migrate
```

### 2. Seed Admin User

Create the default admin account:

```bash
php artisan db:seed --class=AdminUserSeeder
```

**Default Admin Credentials:**
- Email: `admin@cashflow.local`
- Password: `admin123`

⚠️ **Important:** Change the password after first login in production!

### 3. Register Middleware

The `AdminOnly` middleware is already registered in `backend/bootstrap/app.php` with the alias `admin`.

## Accessing the Admin Panel

1. **Login** with the admin credentials at `/login.html`
2. **Navigate** to `/admin.html` or click "Admin Panel" in the sidebar
3. The sidebar link only appears for users with `is_admin = true`

## Admin Features

### Dashboard (`/admin.html`)
- Total users count
- Active subscriptions count
- Monthly revenue
- Total cash entries count
- Recent users list
- Recent payments list
- Quick action links

### User Management (`/admin-users.html`)
- View all users with pagination
- Search by name or email
- Filter admin users only
- View user details (entries, subscriptions, payments)
- Edit user info (name, email, admin status)
- Delete users (with cascade deletion of related data)
- Cannot delete yourself or remove your own admin privileges

### Subscriptions (`/admin-subscriptions.html`)
- View all subscriptions
- Filter by status (active, cancelled, expired)
- Filter by plan (monthly, yearly)
- Update subscription status
- Extend subscription end dates

### Cash Entries (`/admin-entries.html`)
- View all cash entries across users
- Filter by type (income, expense)
- Filter by user
- Filter by date range
- Delete entries

### Payments (`/admin-payments.html`)
- View all payment transactions
- Filter by status (completed, pending, failed)
- Filter by gateway (jazzcash, easypaisa)
- View payment details with user info

### Categories (`/admin-categories.html`)
- View all expense categories
- Create new categories
- Edit category names
- Delete unused categories (prevents deletion if in use)

## API Endpoints

All admin endpoints require authentication (`auth:sanctum`) and admin privileges (`admin` middleware):

```
GET    /api/admin/dashboard              - Dashboard stats
GET    /api/admin/users                  - List users (paginated)
GET    /api/admin/users/{id}             - View user details
PUT    /api/admin/users/{id}             - Update user
DELETE /api/admin/users/{id}             - Delete user
GET    /api/admin/subscriptions          - List subscriptions
PUT    /api/admin/subscriptions/{id}     - Update subscription
GET    /api/admin/cash-entries           - List all entries
DELETE /api/admin/cash-entries/{id}      - Delete entry
GET    /api/admin/payments               - List payments
GET    /api/admin/categories             - List categories
POST   /api/admin/categories             - Create category
PUT    /api/admin/categories/{id}        - Update category
DELETE /api/admin/categories/{id}        - Delete category
```

## Running Tests

### Prerequisites

Ensure both services are running:

```bash
# Terminal 1 - Frontend
npm start

# Terminal 2 - Backend
npm run dev:api
```

### Run Admin Panel Tests

```bash
# Run only admin panel tests (M11)
npm run qa:m11

# Run full regression test (M1-M11)
npm run qa:milestone -- M11
```

### Test Coverage

The admin panel tests (`e2e/tests/m11-admin-panel.spec.js`) cover:

- ✅ Authorization checks (403 for non-admin users)
- ✅ Admin dashboard loading with stats
- ✅ User list API and UI
- ✅ User search functionality
- ✅ User details retrieval
- ✅ User updates (name, email, admin status)
- ✅ Self-demotion prevention
- ✅ User deletion
- ✅ Subscriptions list
- ✅ Payments list
- ✅ Categories CRUD operations
- ✅ Category deletion protection
- ✅ Sidebar visibility (admin vs non-admin)
- ✅ Access denied redirects

### Manual Testing

1. **Login as admin**
   - Use `admin@cashflow.local` / `admin123`

2. **Test user management**
   - Create a test user via signup
   - Search for the user in admin panel
   - Edit user details
   - Grant/revoke admin privileges (on another user)

3. **Test authorization**
   - Login as a regular user
   - Try to access `/admin.html` (should show access denied)
   - Verify no "Admin Panel" link in sidebar

4. **Test subscriptions**
   - Create a test subscription
   - View it in admin panel
   - Update status or end date

5. **Test categories**
   - Create a new category
   - Try to delete a category that's in use (should fail)
   - Delete an unused category (should succeed)

## Troubleshooting

### "Access denied" when accessing admin panel

**Solution:** Ensure your user has `is_admin = true`:

```sql
-- Manually set admin flag
UPDATE users SET is_admin = 1 WHERE email = 'your@email.com';
```

Or use the seeder to create the default admin user.

### Admin link not showing in sidebar

**Cause:** The user object in localStorage doesn't have `is_admin: true`

**Solution:**
1. Logout and login again
2. Or manually update localStorage:
```javascript
const user = JSON.parse(localStorage.getItem('cashflow_auth_user'));
user.is_admin = true;
localStorage.setItem('cashflow_auth_user', JSON.stringify(user));
location.reload();
```

### Tests failing with 403 errors

**Cause:** Admin user not seeded

**Solution:**
```bash
cd backend
php artisan db:seed --class=AdminUserSeeder
```

### Migration already ran error

**Cause:** Migration file timestamp conflict

**Solution:** Check existing migrations:
```bash
cd backend
php artisan migrate:status
```

If migration exists with different timestamp, either:
- Keep the existing one
- Or rollback and re-run: `php artisan migrate:fresh --seed`

## Security Considerations

1. **Change default password** - The seeded admin password is for development only
2. **Audit logging** - Consider adding audit logs for admin actions
3. **Rate limiting** - Admin endpoints use standard auth rate limiting
4. **CSRF protection** - Sanctum provides CSRF protection for state-changing operations
5. **Input validation** - All admin endpoints validate input data

## Production Deployment

Before deploying to production:

1. Change the default admin password
2. Set up proper environment variables
3. Enable HTTPS for all API calls
4. Consider IP whitelisting for admin access
5. Set up monitoring for admin actions
6. Backup database before running migrations

## Additional Notes

- Admin panel follows the same design system as the main app
- Dark/light theme support included
- Responsive design for mobile access
- All data tables support pagination
- Search/filter functionality with debouncing
- Modal confirmations for destructive actions
