@echo off
title PayTrack Live Demo Server
cls
echo ========================================================
echo        PAYTRACK LIVE DEMO SERVER (PC + MOBILE)
echo ========================================================
echo.
echo  PC Access:      http://localhost:8000
echo  Phone Access:   http://192.168.0.199:8000
echo.
echo  --- DEMO CREDENTIALS ---
echo  [ADMIN PORTAL]
echo    Identifier:   admin@paytrack.local
echo    Password:     Admin123!
echo.
echo  [CUSTOMER PWA (MOBILE)]
echo    Identifier:   08111111111  (or bola@paytrack.local)
echo    Password:     CARD001
echo.
echo  Keep this window open while doing your demonstration.
echo  Press Ctrl+C to stop.
echo ========================================================
echo.

cd /d "C:\Users\GREAT OSIO\Documents\PayTrack"
"C:\PHP\php.exe" -S 0.0.0.0:8000
