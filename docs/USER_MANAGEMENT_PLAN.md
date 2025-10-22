# Admin User Management Panel - Implementation Plan

## Overview
Create a comprehensive admin panel for managing users with role-based access control, query limits, license management, and password controls.

## Database Changes

### 1. Add new columns to `users` table:
```sql
ALTER TABLE users ADD COLUMN query_limit_daily INT DEFAULT 100 COMMENT 'Daily search/channel query limit';
ALTER TABLE users ADD COLUMN query_count_today INT DEFAULT 0 COMMENT 'Current day query count';
ALTER TABLE users ADD COLUMN query_reset_date DATE COMMENT 'Last reset date for query counter';
ALTER TABLE users ADD COLUMN license_expires_at TIMESTAMP NULL COMMENT 'License expiration date';
ALTER TABLE users ADD COLUMN notes TEXT NULL COMMENT 'Admin notes about user';
```

### 2. Create `user_activity_log` table:
```sql
CREATE TABLE user_activity_log (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    action_type VARCHAR(50) NOT NULL COMMENT 'login, query, password_change, etc.',
    details TEXT NULL COMMENT 'Additional details in JSON format',
    ip_address VARCHAR(45) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_user_id (user_id),
    INDEX idx_action_type (action_type),
    INDEX idx_created_at (created_at),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

## New Files to Create

### 1. **admin/users.php** - Main user management page
**Features:**
- List all users with filters (active/inactive, role, license status)
- Quick actions: activate/deactivate, edit, delete
- Search users by username/email
- Pagination support
- Sortable columns

**UI Layout:**
```
┌─────────────────────────────────────────────────────────────────┐
│ 👥 Kullanıcı Yönetimi                        [+ Yeni Kullanıcı] │
├─────────────────────────────────────────────────────────────────┤
│ Filtrele: [Tümü ▼] [Aktif ▼] [Lisans Durumu ▼] [Ara...]       │
├─────────────────────────────────────────────────────────────────┤
│ Kullanıcı    | Email        | Rol   | Sorgu  | Lisans | Aksiyon│
│ admin        | admin@...    | Admin | ∞      | -      | [Düz]  │
│ john_doe     | john@...     | User  | 45/100 | 30 gün | [Düz]  │
│ jane_smith   | jane@...     | User  | 0/50   | Doldu  | [Düz]  │
└─────────────────────────────────────────────────────────────────┘
```

### 2. **admin/user-edit.php** - Edit user details
**Features:**
- Change username, email, full name
- Set role (admin/user)
- Set query limits (daily)
- Set license expiration date
- Reset password (admin can set new password)
- Activate/deactivate account
- Add admin notes
- View user statistics

**Form Sections:**
1. **Temel Bilgiler**
   - Kullanıcı Adı
   - E-posta
   - Tam Ad
   - Rol (Admin/User)

2. **Limitler ve Lisans**
   - Günlük Sorgu Limiti
   - Lisans Bitiş Tarihi

3. **Güvenlik**
   - Yeni Şifre Belirle
   - Hesap Durumu (Aktif/Pasif)

4. **Notlar**
   - Admin Notları (textarea)

5. **İstatistikler**
   - Toplam Sorgu Sayısı
   - Bugünkü Sorgu Sayısı
   - Son Giriş Tarihi
   - Kayıt Tarihi

### 3. **admin/user-create.php** - Create new user
**Features:**
- All fields from user-edit
- Auto-generate password option
- Send welcome email option (future feature)
- Set initial query limits
- Set license duration

### 4. **admin/user-activity.php** - View user activity log
**Features:**
- Filter by user, action type, date range
- Export to CSV
- Pagination
- Real-time activity monitoring

**Activity Types:**
- `login` - User logged in
- `logout` - User logged out
- `query_search` - Performed search query
- `query_channel` - Performed channel query
- `password_change` - Changed password
- `profile_update` - Updated profile
- `limit_reached` - Hit query limit
- `license_expired` - License expired

### 5. **profile.php** - User profile page (for all users)
**Features:**
- View own profile (read-only)
- Change own password
- View query usage statistics
- View license expiration
- View recent activity

**UI Sections:**
1. **Profil Bilgileri**
   - Kullanıcı Adı (read-only)
   - E-posta (read-only)
   - Tam Ad (read-only)
   - Rol (read-only)

2. **Şifre Değiştir**
   - Mevcut Şifre
   - Yeni Şifre
   - Yeni Şifre (Tekrar)

3. **Kullanım İstatistikleri**
   - Bugünkü Sorgu: 45/100
   - Bu Ay Toplam: 1,234
   - Tüm Zamanlar: 12,456

4. **Lisans Bilgileri**
   - Lisans Durumu: Aktif
   - Bitiş Tarihi: 2025-12-31
   - Kalan Gün: 30

### 6. **lib/UserManager.php** - User management class
**Methods:**
```php
class UserManager {
    // User CRUD
    public static function getAllUsers(array $filters = []): array
    public static function getUserById(int $userId): ?array
    public static function createUser(array $data): array
    public static function updateUser(int $userId, array $data): bool
    public static function deleteUser(int $userId): bool

    // Query Limits
    public static function checkQueryLimit(int $userId): bool
    public static function incrementQueryCount(int $userId): void
    public static function resetQueryCountIfNeeded(int $userId): void
    public static function getRemainingQueries(int $userId): int

    // License Management
    public static function isLicenseValid(int $userId): bool
    public static function getLicenseInfo(int $userId): array
    public static function extendLicense(int $userId, string $expiresAt): bool

    // Activity Logging
    public static function logActivity(int $userId, string $actionType, ?array $details = null): void
    public static function getUserActivity(int $userId, array $filters = []): array

    // Statistics
    public static function getUserStats(int $userId): array
    public static function getSystemStats(): array
}
```

## UI Components

### Admin Navbar Addition:
```php
<?php if (Auth::isAdmin()): ?>
    <a class="..." href="admin/users.php">
        👥 Kullanıcılar
    </a>
<?php endif; ?>
```

### User Dashboard Widget (on index.php):
```html
<div class="bg-blue-50 border border-blue-200 rounded-lg p-3 mb-4">
    <div class="flex justify-between items-center">
        <div>
            <span class="text-sm text-blue-700">Bugünkü Sorgu:</span>
            <span class="font-bold text-blue-900">45/100</span>
        </div>
        <div>
            <span class="text-sm text-blue-700">Lisans:</span>
            <span class="font-bold text-blue-900">30 gün kaldı</span>
        </div>
    </div>
</div>
```

## Features Detail

### 1. Query Limit System
**Implementation:**
```php
// Before each search/channel query
if (!UserManager::checkQueryLimit(Auth::userId())) {
    $error = 'Günlük sorgu limitinize ulaştınız.';
    // Show error and block query
}

// After successful query
UserManager::incrementQueryCount(Auth::userId());
UserManager::logActivity(Auth::userId(), 'query_search', ['query' => $query]);
```

**Auto-reset Logic:**
- Check `query_reset_date` on each query
- If date is different from today, reset `query_count_today` to 0
- Update `query_reset_date` to today

**Admin Exception:**
- Admin users have unlimited queries (`query_limit_daily = -1` or `role = 'admin'`)

### 2. License System
**Implementation:**
```php
// On login
if (!UserManager::isLicenseValid(Auth::userId())) {
    // Redirect to license expired page
    // Allow access only to profile.php
}

// Show warning 7 days before expiration
$licenseInfo = UserManager::getLicenseInfo(Auth::userId());
if ($licenseInfo['days_remaining'] <= 7 && $licenseInfo['days_remaining'] > 0) {
    // Show warning banner
}
```

**License States:**
- `active` - License is valid
- `expiring_soon` - Less than 7 days remaining
- `expired` - License has expired
- `unlimited` - Admin or no expiration set

### 3. Activity Logging
**Logged Actions:**
- User login/logout
- Search queries (keyword + result count)
- Channel queries (channel ID + result count)
- Password changes
- Profile updates
- Query limit reached
- License expiration

**Log Format:**
```json
{
    "action": "query_search",
    "query": "izmir gece sokak lezzetleri",
    "results": 10,
    "timestamp": "2025-10-23 12:34:56"
}
```

### 4. User Statistics
**Metrics:**
- Total queries (all time)
- Queries today
- Queries this month
- Average queries per day
- Last login date
- Account age
- License status

## Security Features

### 1. Access Control
```php
// Admin-only pages
Auth::requireAdmin(); // Throws exception if not admin

// User can only edit own profile
if (Auth::userId() !== $userId && !Auth::isAdmin()) {
    throw new Exception('Unauthorized');
}
```

### 2. Password Security
- Minimum 8 characters
- Must contain: uppercase, lowercase, number
- Password strength indicator
- Bcrypt hashing

### 3. CSRF Protection
```php
// Generate token
$_SESSION['csrf_token'] = bin2hex(random_bytes(32));

// Validate token
if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
    throw new Exception('Invalid CSRF token');
}
```

### 4. Input Validation
- Sanitize all user inputs
- Validate email format
- Check username uniqueness
- Validate date formats
- Prevent SQL injection (use prepared statements)

## Implementation Steps

### Phase 1: Database & Core (Day 1)
1. ✅ Create database migration file
2. ✅ Run migration to add columns and tables
3. ✅ Create UserManager class with core methods
4. ✅ Add Auth::isAdmin() and Auth::requireAdmin() methods

### Phase 2: Admin Pages (Day 2)
5. ✅ Create admin/users.php (user list)
6. ✅ Create admin/user-edit.php (edit user)
7. ✅ Create admin/user-create.php (create user)
8. ✅ Create admin/user-activity.php (activity log)

### Phase 3: User Features (Day 3)
9. ✅ Create profile.php (user profile)
10. ✅ Add query limit checks to index.php
11. ✅ Add query limit checks to channel.php
12. ✅ Add license validation on login

### Phase 4: UI & Polish (Day 4)
13. ✅ Update navbar with admin menu
14. ✅ Add dashboard widgets for query/license info
15. ✅ Add warning banners for limits/license
16. ✅ Style all new pages consistently

### Phase 5: Testing (Day 5)
17. ✅ Test user creation/editing/deletion
18. ✅ Test query limits (normal user)
19. ✅ Test license expiration
20. ✅ Test admin permissions
21. ✅ Test activity logging
22. ✅ Security testing (CSRF, XSS, SQL injection)

## File Structure
```
ymt-lokal/
├── admin/
│   ├── users.php              # User list page
│   ├── user-edit.php          # Edit user page
│   ├── user-create.php        # Create user page
│   └── user-activity.php      # Activity log page
├── profile.php                # User profile page
├── lib/
│   ├── UserManager.php        # User management class
│   └── Auth.php               # Updated with admin checks
├── database/
│   └── migrations/
│       └── 003_user_management.sql  # Database migration
├── docs/
│   └── USER_MANAGEMENT_PLAN.md      # This file
└── includes/
    └── navbar.php             # Updated with admin menu
```

## API Endpoints (Future)

For future API integration:
```
GET    /api/users              # List users (admin)
GET    /api/users/:id          # Get user details (admin)
POST   /api/users              # Create user (admin)
PUT    /api/users/:id          # Update user (admin)
DELETE /api/users/:id          # Delete user (admin)
GET    /api/users/:id/stats    # Get user stats (admin)
GET    /api/users/:id/activity # Get user activity (admin)
GET    /api/profile            # Get own profile
PUT    /api/profile/password   # Change own password
```

## Testing Checklist

### User Management
- [ ] Create new user with all fields
- [ ] Edit existing user
- [ ] Delete user
- [ ] Search users by username/email
- [ ] Filter users by role/status/license
- [ ] Pagination works correctly

### Query Limits
- [ ] Normal user hits daily limit
- [ ] Query count resets at midnight
- [ ] Admin has unlimited queries
- [ ] Warning shown at 80% usage
- [ ] Queries blocked when limit reached

### License Management
- [ ] License expiration warning (7 days)
- [ ] Access blocked when expired
- [ ] Admin can extend license
- [ ] License info shown on profile

### Security
- [ ] Non-admin cannot access admin pages
- [ ] User cannot edit other users
- [ ] CSRF protection works
- [ ] Password validation works
- [ ] SQL injection prevented
- [ ] XSS prevented

### Activity Logging
- [ ] Login/logout logged
- [ ] Queries logged
- [ ] Password changes logged
- [ ] Activity log filterable
- [ ] Activity log exportable

## Future Enhancements

1. **Email Notifications**
   - Welcome email on user creation
   - Password reset email
   - License expiration reminder
   - Query limit warning

2. **Advanced Analytics**
   - User engagement metrics
   - Query patterns analysis
   - Peak usage times
   - Popular search terms

3. **Bulk Operations**
   - Bulk user import (CSV)
   - Bulk license extension
   - Bulk user activation/deactivation

4. **API Access**
   - RESTful API for user management
   - API key management
   - Rate limiting per API key

5. **Two-Factor Authentication**
   - TOTP-based 2FA
   - Backup codes
   - SMS verification (optional)

## Notes

- All dates/times should be stored in UTC
- Use prepared statements for all database queries
- Log all admin actions for audit trail
- Implement soft delete for users (keep data but mark as deleted)
- Regular backups of user_activity_log table
- Consider GDPR compliance for user data

---

**Document Version:** 1.0
**Created:** 2025-10-23
**Last Updated:** 2025-10-23
**Author:** Claude Code
**Status:** Ready for Implementation
