# XAMPP Setup

1. Copy the folder `PATCH_P1_001_Malbcoff_Phase1` into:
   `C:\xampp\htdocs\malbcoff`

2. Start **Apache** and **MySQL** from XAMPP Control Panel.

3. Open phpMyAdmin:
   `http://localhost/phpmyadmin`

4. Import:
   `database/malbcoff_pos.sql`

5. Optional: import `database/demo_seed.sql` if you want sample inventory on the dashboard.

6. Open:
   `http://localhost/malbcoff/`

## Demo accounts
- Owner: `owner@malbcoff.local` / `Owner@123`
- Branch 1: `branch1@malbcoff.local` / `Branch@123`
- Branch 2/3/4 use the same Branch password and matching branch email.

## Database config
Default XAMPP config is already set in `config/database.php`:
- Host: `127.0.0.1`
- Database: `malbcoff_pos`
- Username: `root`
- Password: blank

Change only this file if your MySQL credentials differ.
