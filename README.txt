TRAVEL BUEA - BACKEND (PHP + MySQL for Laragon)
================================================

FILES
  index.html  - your website, now connected to the server (replaces TravelBuea-OBRS.html)
  api.php     - the backend (all the server logic)
  config.php  - database settings (defaults already match Laragon)
  setup.php   - one-time page that creates the database and the admin account

INSTALL (5 minutes)
  1. Open Laragon and click "Start All" (Apache and MySQL must both be running).
  2. Copy this whole folder to  C:\laragon\www\travelbuea
  3. In Laragon: right-click > Apache > "Reload" (or Stop All, then Start All).
  4. In your browser open:   http://localhost/travelbuea/setup.php
     (or http://travelbuea.test/setup.php if Laragon's auto virtual host is on)
  5. Type an admin password and click "Create database".
  6. Open  http://localhost/travelbuea/index.html  - the site is now live on your database.

LOGINS
  Customers : Sign Up on the site (saved in the MySQL "users" table).
  Admin     : admin@travelbuea.cm  +  the password you chose in step 5.

SEE YOUR DATA
  Laragon > Database > HeidiSQL > database "travelbuea" > tables:
  users, schedules, bookings, payments, api_tokens.

IMPORTANT
  - Open the site through http://localhost/... (not by double-clicking the file).
  - To re-run setup, delete setup.lock. Delete setup.php before putting the site online.
  - The Mobile Money screen is still a simulation; payments are only recorded in the
    "payments" table. Real MTN MoMo / Orange Money needs their developer API (see api.php,
    "bookings" route, where the comment says so).
