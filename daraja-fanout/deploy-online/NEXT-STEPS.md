NEXT STEP ??? relay online ledger -> local machines (30 minutes, one time)

== On Dr Bii's domain (he does this) ==
1. Upload transactions.php to public_html (next to callback.php).
2. In transactions.php CONFIG: set DB_HOST/DB_NAME/DB_USER/DB_PASS to the same
   values as callback.php. Choose API_USER + API_PASS (a strong password) ??? then
   send you those two.
3. Test in browser (it will ask for the user/pass):
   https://yourdomain/transactions.php?limit=5
   -> JSON with the CHECKDIAL1 row confirms the read side works.

acknowledgment: tell him NOT to share that password publicly and that it should be different from his cPanel password.

== On your local machine (you do this) ==
1. Edit daraja-fanout\local\.env:
      ONLINE_URL=https://yourdomain/transactions.php
      ONLINE_USER=<he sends you>
      ONLINE_PASS=<he sends you>
      SYNC_INTERVAL_SEC=60
2. Right-click daraja-fanout\local\install-local-starter.bat > Run as administrator (ONCE).
   This installs 4 startup tasks:
      - Daraja Sync Worker  (pulls ??? new rows every 60s)
      - Application A dashboard on http://localhost:9001
      - Application B dashboard on http://localhost:9002
      - (optional local middleware on :9000)
   These auto-start at every login from now on - zero maintenance.
3. First run happens immediately: the CHECKDIAL1 row joins both local dashboards.

== Optional real-time row check instantly (read without waiting the full 60s):
   powershell:
   Start-Sleep -Seconds 70; (Invoke-WebRequest "http://localhost:9001/transactions" -Headers @{Authorization="Basic YWRtaW46Y2hhbmdlbWU="}).Content

== Go live with Safaricom (pending one portal fix)
The RegisterURL call is ready (`daraja-fanout\deploy-online\register-urls.php`) but
Safaricom rejected it with "no apiproduct match found" - his Daraja app lacks the C2B
API product. Tell him to enable MPesa C2B on the app (or paste the callback URL manually
in the portal under "C2B URL registration"). The moment that is done:

   & "C:\xampp\php\php.exe" "daraja-fanout\deploy-online\register-urls.php"

From then on Safaricom auto-posts ALL paybill 324364 transactions to his callback.php.
