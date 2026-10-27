# How to Add Superadmin Role to Your Account

## Quick Steps

### Step 1: Open phpMyAdmin
1. Go to `http://localhost/phpmyadmin`
2. Login with your MySQL credentials

### Step 2: Select Your Database
1. Click on your database: `u514031374_lgu_research_d` (or your main LGU database)
2. Click on the **SQL** tab at the top

### Step 3: Run the SQL Query

Copy and paste this SQL query:

```sql
UPDATE users 
SET user_type = 'superadmin' 
WHERE email = 'diplomamart@gmail.com';
```

**Or if you prefer to update by username:**

```sql
UPDATE users 
SET user_type = 'superadmin' 
WHERE username = 'Jane Doe';
```

Click **Go** to execute the query.

### Step 4: Verify the Update

Run this query to confirm:

```sql
SELECT id, username, fullname, email, user_type, status 
FROM users 
WHERE email = 'diplomamart@gmail.com';
```

You should see `user_type` is now `superadmin`.

### Step 5: Logout and Login Again

**IMPORTANT:** You must logout and login again for the session to pick up the new role.

1. Click your profile dropdown
2. Click **Logout**
3. Login again with your credentials
4. Your session will now have `user_type = 'superadmin'`

### Step 6: Test SSO Access

1. After logging in, click **Super Admin Panel** in the sidebar
2. Click on **Records and Correspondence** card
3. You should now be automatically logged into RACS without seeing the error

## Why This Works

- **Before:** Your role was `admin` or `research`
- **After:** Your role is `superadmin`
- **RACS accepts:** Only `superadmin` role for SSO
- **Result:** SSO token will have `role: 'superadmin'` and RACS will accept it

## Alternative Solution (Recommended for Long-term)

Instead of changing your role to `superadmin`, you could ask the RACS administrators to update their system to accept `admin` and `research` roles. This way:

- You keep your original role
- Multiple users with `admin` or `research` roles can access RACS
- More flexible and scalable

See `SSO_SETUP_GUIDE.md` for details on how RACS should be updated.

## Troubleshooting

### "Still getting the error after updating"
- Make sure you logged out and logged in again
- Check that the SQL query actually updated the record
- Clear your browser cookies/cache

### "Can't find my user in the database"
Run this query to see all users:
```sql
SELECT id, username, fullname, email, user_type FROM users;
```

### "Want to change back to admin"
```sql
UPDATE users 
SET user_type = 'admin' 
WHERE email = 'diplomamart@gmail.com';
```

Then logout and login again.
