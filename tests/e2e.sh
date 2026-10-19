#!/bin/bash
# ============================================================
# PROS AUTO — Suite de tests E2E (curl + cookie jar)
# ============================================================
BASE="http://localhost/garage1"
export PATH="/c/xampp/mysql/bin:$PATH"
JAR_A=/tmp/jar_admin.txt
JAR_M=/tmp/jar_mohamed.txt
rm -f $JAR_A $JAR_M
PASS=0; FAIL=0

ok()   { PASS=$((PASS+1)); echo "  âœ” $1"; }
ko()   { FAIL=$((FAIL+1)); echo "  âœ˜ $1  â†’ $2"; }
check(){ if [ "$1" = "$2" ]; then ok "$3"; else ko "$3" "got '$1' expected '$2'"; fi; }

get_token() { # $1=jar $2=url
  curl -s -b "$1" -c "$1" "$2" | grep -o 'csrf_token" value="[a-f0-9]*"' | head -1 | sed 's/.*value="//;s/"//'
}

echo "=== 1. LOGIN ADMIN ==="
T=$(curl -s -c $JAR_A $BASE/auth/login.php | grep -o 'csrf_token" value="[a-f0-9]*"' | head -1 | sed 's/.*value="//;s/"//')
CODE=$(curl -s -b $JAR_A -c $JAR_A -o /tmp/login_resp.html -w '%{http_code}' -X POST $BASE/auth/login.php \
  --data-urlencode "csrf_token=$T" --data-urlencode "identity=admin" --data-urlencode "password=Admin@2026")
check "$CODE" "302" "POST login admin (302)"
grep -q "Dashboard" /tmp/login_resp.html 2>/dev/null
curl -s -b $JAR_A -c $JAR_A -o /tmp/dash.html -w '%{http_code}' $BASE/admin/dashboard.php > /tmp/c
check "$(cat /tmp/c)" "200" "GET admin/dashboard.php (200)"
grep -q "Tableau de bord" /tmp/dash.html && ok "dashboard FR affichÃ©" || ko "dashboard FR" "texte introuvable"

echo "=== 2. LOGIN BAD PASSWORD (anti force brute) ==="
T=$(curl -s -c /tmp/jar_bad.txt $BASE/auth/login.php | grep -o 'csrf_token" value="[a-f0-9]*"' | head -1 | sed 's/.*value="//;s/"//')
curl -s -b /tmp/jar_bad.txt -c /tmp/jar_bad.txt -X POST $BASE/auth/login.php \
  --data-urlencode "csrf_token=$T" --data-urlencode "identity=admin" --data-urlencode "password=WRONG" -o /tmp/bad.html
grep -q "Identifiant ou mot de passe incorrect" /tmp/bad.html && ok "mauvais mot de passe rejetÃ©" || ko "mauvais mdp" "message absent"

echo "=== 2b. LOCKOUT APRÃˆS 5 Ã‰CHECS ==="
mysql -u root -h 127.0.0.1 garage_db1 -e "DELETE FROM login_attempts;" 2>/dev/null
rm -f /tmp/jar_lock.txt
for i in 1 2 3 4 5; do
    T=$(curl -s -c /tmp/jar_lock.txt "$BASE/auth/login.php" | grep -o 'csrf_token" value="[a-f0-9]*"' | head -1 | sed 's/.*value="//;s/"//')
    curl -s -b /tmp/jar_lock.txt -c /tmp/jar_lock.txt -o /dev/null -X POST "$BASE/auth/login.php" \
        --data-urlencode "csrf_token=$T" --data-urlencode "identity=admin" --data-urlencode "password=bad$i"
done
T=$(curl -s -b /tmp/jar_lock.txt -c /tmp/jar_lock.txt "$BASE/auth/login.php" | grep -o 'csrf_token" value="[a-f0-9]*"' | head -1 | sed 's/.*value="//;s/"//')
CODE=$(curl -s -b /tmp/jar_lock.txt -c /tmp/jar_lock.txt -o /tmp/lock.html -w '%{http_code}' -X POST "$BASE/auth/login.php" \
    --data-urlencode "csrf_token=$T" --data-urlencode "identity=admin" --data-urlencode "password=Admin@2026")
check "$CODE" "200" "6e tentative bloquÃ©e (pas de 302)"
grep -q "Trop de tentatives" /tmp/lock.html && ok "message de blocage affichÃ© (mÃªme mdp correct)" || ko "lockout" "message absent"
mysql -u root -h 127.0.0.1 garage_db1 -e "DELETE FROM login_attempts;" 2>/dev/null

echo "=== 3. CRUD CLIENT ==="
T=$(get_token $JAR_A $BASE/admin/clients/form.php)
CODE=$(curl -s -b $JAR_A -c $JAR_A -o /dev/null -w '%{http_code}' -X POST $BASE/admin/clients/form.php \
  --data-urlencode "csrf_token=$T" --data-urlencode "civility=Monsieur" \
  --data-urlencode "last_name=Brown" --data-urlencode "first_name=Sami" \
  --data-urlencode "phone=+1 (416) 555-0101" --data-urlencode "phone2=" \
  --data-urlencode "email=sami.brown@prosauto.ca" --data-urlencode "address=245 King Street West" \
  --data-urlencode "postal_code=M5V 3K2" --data-urlencode "city=Toronto" --data-urlencode "notes=VIP client")
check "$CODE" "302" "POST nouveau client (302)"
curl -s -b $JAR_A $BASE/admin/clients/index.php > /tmp/cl.html
grep -q "Brown" /tmp/cl.html && ok "client visible dans la liste" || ko "client liste" "introuvable"
CID=$(mysql -u root -h 127.0.0.1 --default-character-set=utf8mb4 -N garage_db1 -e "SELECT id FROM clients WHERE last_name='Brown'")
ok "client id=$CID"
# XSS : crÃ©er un client avec script
T=$(get_token $JAR_A $BASE/admin/clients/form.php)
curl -s -b $JAR_A -c $JAR_A -o /dev/null -X POST $BASE/admin/clients/form.php \
  --data-urlencode "csrf_token=$T" --data-urlencode "civility=Madame" \
  --data-urlencode "last_name=<script>alert(1)</script>" --data-urlencode "first_name=Test" \
  --data-urlencode "email=test-xss@prosauto.ca"
curl -s -b $JAR_A $BASE/admin/clients/index.php > /tmp/xss.html
grep -q "<script>alert(1)</script>" /tmp/xss.html && ko "XSS Ã©chappÃ©" "script brut trouvÃ© !" || ok "XSS correctement Ã©chappÃ©"
XSSID=$(mysql -u root -h 127.0.0.1 --default-character-set=utf8mb4 -N garage_db1 -e "SELECT id FROM clients WHERE email='test-xss@prosauto.ca'")
# suppression de client avec vÃ©hicule => bloquÃ©e
# (test plus bas aprÃ¨s crÃ©ation vÃ©hicule)

echo "=== 4. CRUD VÃ‰HICULE ==="
T=$(get_token $JAR_A $BASE/admin/vehicles/form.php)
CODE=$(curl -s -b $JAR_A -c $JAR_A -o /dev/null -w '%{http_code}' -X POST $BASE/admin/vehicles/form.php \
  --data-urlencode "csrf_token=$T" --data-urlencode "client_id=$CID" \
  --data-urlencode "license_plate=ABC 123" --data-urlencode "brand=Toyota" \
  --data-urlencode "model=Camry" --data-urlencode "year=2021" --data-urlencode "vin=SHFDB2BEBLS123456" \
  --data-urlencode "mileage=45200" --data-urlencode "fuel=Gasoline" --data-urlencode "color=Gray" \
  --data-urlencode "entry_date=2026-09-15" --data-urlencode "notes=")
check "$CODE" "302" "POST nouveau vÃ©hicule (302)"
VID=$(mysql -u root -h 127.0.0.1 --default-character-set=utf8mb4 -N garage_db1 -e "SELECT id FROM vehicles WHERE license_plate='ABC 123'")
ST=$(mysql -u root -h 127.0.0.1 --default-character-set=utf8mb4 -N garage_db1 -e "SELECT status FROM vehicles WHERE id=$VID")
check "$ST" "initial" "vÃ©hicule crÃ©Ã© avec Ã©tat initial"
# doublon immatriculation
T=$(get_token $JAR_A $BASE/admin/vehicles/form.php)
curl -s -b $JAR_A -c $JAR_A -o /tmp/dup.html -X POST $BASE/admin/vehicles/form.php \
  --data-urlencode "csrf_token=$T" --data-urlencode "client_id=$CID" \
  --data-urlencode "license_plate=ABC 123" --data-urlencode "brand=Honda" \
  --data-urlencode "model=Civic" --data-urlencode "year=2022" --data-urlencode "vin=" \
  --data-urlencode "mileage=" --data-urlencode "fuel=" --data-urlencode "color=" \
  --data-urlencode "entry_date=2026-09-15" --data-urlencode "notes="
grep -q "immatriculation existe dÃ©jÃ " /tmp/dup.html && ok "immatriculation en doublon refusÃ©e" || ko "doublon immat" "refus absent"
# suppression d'un client ayant un vÃ©hicule => refusÃ©e
T=$(get_token $JAR_A $BASE/admin/clients/index.php)
curl -s -b $JAR_A -c $JAR_A -o /tmp/delcl.html -X POST "$BASE/admin/clients/index.php" \
  --data-urlencode "csrf_token=$T" --data-urlencode "action=delete" --data-urlencode "id=$CID"
CIDST=$(mysql -u root -h 127.0.0.1 --default-character-set=utf8mb4 -N garage_db1 -e "SELECT COUNT(*) FROM clients WHERE id=$CID")
check "$CIDST" "1" "client avec vÃ©hicule non supprimÃ©"

echo "=== 5. INSPECTION â†’ Ã©tat EN COURS ==="
MECH=$(mysql -u root -h 127.0.0.1 --default-character-set=utf8mb4 -N garage_db1 -e "SELECT id FROM mechanics WHERE is_active=1 ORDER BY id LIMIT 1")
# inspection sans mecanicien => refusee (champ obligatoire)
T=$(get_token $JAR_A $BASE/admin/inspections/form.php)
curl -s -b $JAR_A -c $JAR_A -o /tmp/nomech.html -X POST $BASE/admin/inspections/form.php \
  --data-urlencode "csrf_token=$T" --data-urlencode "vehicle_id=$VID" \
  --data-urlencode "mileage=45210" --data-urlencode "inspection_date=2026-09-15" \
  --data-urlencode "mechanic_id=" --data-urlencode "problem_description=Test sans mecanicien" \
  --data-urlencode "initial_diagnostic=" --data-urlencode "parts_to_check=" \
  --data-urlencode "observations=" --data-urlencode "photo="
grep -q "alert-danger" /tmp/nomech.html && ok "inspection sans mecanicien refusee (alerte)" || ko "mecanicien obligatoire" "aucune alerte"
T=$(get_token $JAR_A $BASE/admin/inspections/form.php)
CODE=$(curl -s -b $JAR_A -c $JAR_A -o /dev/null -w '%{http_code}' -X POST $BASE/admin/inspections/form.php \
  --data-urlencode "csrf_token=$T" --data-urlencode "vehicle_id=$VID" \
  --data-urlencode "mileage=45210" \

  --data-urlencode "inspection_date=2026-09-15" --data-urlencode "mechanic_id=$MECH" \
  --data-urlencode "problem_description=Bruit au niveau du train avant, direction dure" \
  --data-urlencode "initial_diagnostic=Amortisseurs Ã  remplacer, vÃ©rification roulement" \
  --data-urlencode "parts_to_check=Amortisseurs avant, roulements, cardan" \
  --data-urlencode "observations=Vidange Ã  prÃ©voir" --data-urlencode "photo=")
check "$CODE" "302" "POST inspection (302)"
ST=$(mysql -u root -h 127.0.0.1 --default-character-set=utf8mb4 -N garage_db1 -e "SELECT status FROM vehicles WHERE id=$VID")
check "$ST" "en_cours" "vÃ©hicule passÃ© Ã  EN COURS aprÃ¨s inspection"
HIST=$(mysql -u root -h 127.0.0.1 --default-character-set=utf8mb4 -N garage_db1 -e "SELECT COUNT(*) FROM vehicle_status_history WHERE vehicle_id=$VID")
[ "$HIST" -ge 2 ] && ok "historique des Ã©tats rempli ($HIST lignes)" || ko "historique" "$HIST lignes"
IID=$(mysql -u root -h 127.0.0.1 --default-character-set=utf8mb4 -N garage_db1 -e "SELECT id FROM inspections WHERE vehicle_id=$VID")

echo "=== 6. AFFECTATION â†’ Ã©tat EN RÃ‰PARATION ==="
T=$(get_token $JAR_A $BASE/admin/assignments/form.php)
CODE=$(curl -s -b $JAR_A -c $JAR_A -o /dev/null -w '%{http_code}' -X POST $BASE/admin/assignments/form.php \
  --data-urlencode "csrf_token=$T" --data-urlencode "vehicle_id=$VID" \
  --data-urlencode "inspection_id=$IID" --data-urlencode "mechanic_id=1" \
  --data-urlencode "assigned_date=2026-09-16" --data-urlencode "assigned_time=09:30" \
  --data-urlencode "priority=haute" --data-urlencode "instructions=Penser Ã  contrÃ´ler le cardan avant")
check "$CODE" "302" "POST affectation (302)"
ST=$(mysql -u root -h 127.0.0.1 --default-character-set=utf8mb4 -N garage_db1 -e "SELECT status FROM vehicles WHERE id=$VID")
check "$ST" "en_reparation" "vÃ©hicule passÃ© Ã  EN RÃ‰PARATION"
REPAIR=$(mysql -u root -h 127.0.0.1 --default-character-set=utf8mb4 -N garage_db1 -e "SELECT COUNT(*) FROM repairs WHERE vehicle_id=$VID")
[ "$REPAIR" -ge 1 ] && ok "rÃ©paration auto-crÃ©Ã©e" || ko "rÃ©paration auto" "absente"
NOTIF=$(mysql -u root -h 127.0.0.1 --default-character-set=utf8mb4 -N garage_db1 -e "SELECT COUNT(*) FROM notifications WHERE user_id=2")
[ "$NOTIF" -ge 1 ] && ok "notification crÃ©Ã©e pour le mÃ©canicien" || ko "notification mÃ©ca" "absente"

echo "=== 7. ESPACE MÃ‰CANICIEN ==="
T=$(curl -s -c $JAR_M $BASE/auth/login.php | grep -o 'csrf_token" value="[a-f0-9]*"' | head -1 | sed 's/.*value="//;s/"//')
CODE=$(curl -s -b $JAR_M -c $JAR_M -o /dev/null -w '%{http_code}' -X POST $BASE/auth/login.php \
  --data-urlencode "csrf_token=$T" --data-urlencode "identity=mohamed" --data-urlencode "password=Mech@2026")
check "$CODE" "302" "login mÃ©canicien (302)"
curl -s -b $JAR_M $BASE/mechanic/dashboard.php > /tmp/mdash.html -o /tmp/mdash.html
grep -q "ABC 123" /tmp/mdash.html && ok "mÃ©canicien voit son vÃ©hicule" || ko "dash mÃ©ca" "vÃ©hicule introuvable"
# accÃ¨s admin interdit
CODE=$(curl -s -b $JAR_M -o /dev/null -w '%{http_code}' $BASE/admin/clients/index.php)
check "$CODE" "403" "mÃ©canicien bloquÃ© sur /admin (403)"
CODE=$(curl -s -b $JAR_M -o /dev/null -w '%{http_code}' $BASE/admin/dashboard.php)
check "$CODE" "403" "mÃ©canicien bloquÃ© sur dashboard admin (403)"
# vÃ©hicule d'un autre mÃ©canicien invisible
T=$(get_token $JAR_A $BASE/admin/vehicles/form.php)
curl -s -b $JAR_A -c $JAR_A -o /dev/null -X POST $BASE/admin/vehicles/form.php \
  --data-urlencode "csrf_token=$T" --data-urlencode "client_id=$CID" \
  --data-urlencode "license_plate=999 TT 0000" --data-urlencode "brand=Renault" \
  --data-urlencode "model=Clio" --data-urlencode "year=2020" --data-urlencode "vin=" \
  --data-urlencode "mileage=" --data-urlencode "fuel=" --data-urlencode "color=" \
  --data-urlencode "entry_date=2026-09-15" --data-urlencode "notes="
VID2=$(mysql -u root -h 127.0.0.1 --default-character-set=utf8mb4 -N garage_db1 -e "SELECT id FROM vehicles WHERE license_plate='999 TT 0000'")
CODE=$(curl -s -b $JAR_M -o /dev/null -w '%{http_code}' "$BASE/mechanic/vehicle_view.php?id=$VID2")
check "$CODE" "302" "vÃ©hicule non affectÃ© : redirection (pas de 200)"

echo "=== 8. RÃ‰PARATION : travaux + piÃ¨ces + TERMINER ==="
RID=$(mysql -u root -h 127.0.0.1 --default-character-set=utf8mb4 -N garage_db1 -e "SELECT id FROM repairs WHERE vehicle_id=$VID ORDER BY id DESC LIMIT 1")
T=$(get_token $JAR_M $BASE/mechanic/vehicle_view.php?id=$VID)
CODE=$(curl -s -b $JAR_M -c $JAR_M -o /dev/null -w '%{http_code}' -X POST $BASE/mechanic/vehicle_view.php?id=$VID \
  --data-urlencode "csrf_token=$T" --data-urlencode "action=update" \
  --data-urlencode "works_done=Remplacement des 2 amortisseurs avant, graissage" \
  --data-urlencode "labor_cost=350.50" --data-urlencode "comments=Travail propre" \
  --data-urlencode "remove_part=0" --data-urlencode "new_part_desc=Amortisseur avant Dacia" \
  --data-urlencode "new_part_qty=2" --data-urlencode "new_part_price=120")
check "$CODE" "302" "POST update rÃ©paration (302)"
PT=$(mysql -u root -h 127.0.0.1 --default-character-set=utf8mb4 -N garage_db1 -N -e "SELECT COALESCE(SUM(total),0) FROM repair_parts WHERE repair_id=$RID")
LAB=$(mysql -u root -h 127.0.0.1 --default-character-set=utf8mb4 -N garage_db1 -e "SELECT labor_cost FROM repairs WHERE id=$RID")
check "$PT" "240.00" "total piÃ¨ces = 2 x 120 = 240.00"
check "$LAB" "350.50" "main-d'Å“uvre enregistrÃ©e"
# terminer la rÃ©paration
T=$(get_token $JAR_M $BASE/mechanic/vehicle_view.php?id=$VID)
CODE=$(curl -s -b $JAR_M -c $JAR_M -o /dev/null -w '%{http_code}' -X POST $BASE/mechanic/vehicle_view.php?id=$VID \
  --data-urlencode "csrf_token=$T" --data-urlencode "action=finish" \
  --data-urlencode "finish_comment=VÃ©hicule testÃ© sur route, tout est OK")
check "$CODE" "302" "POST terminer rÃ©paration (302)"
ST=$(mysql -u root -h 127.0.0.1 --default-character-set=utf8mb4 -N garage_db1 -e "SELECT status FROM vehicles WHERE id=$VID")
check "$ST" "repare" "vÃ©hicule passÃ© Ã  RÃ‰PARÃ‰"
NOTIF=$(mysql -u root -h 127.0.0.1 --default-character-set=utf8mb4 -N garage_db1 -e "SELECT COUNT(*) FROM notifications WHERE user_id=1 AND title LIKE '%RÃ©paration terminÃ©e%'")
[ "$NOTIF" -ge 1 ] && ok "notification admin : rÃ©paration terminÃ©e" || ko "notif admin rÃ©pa" "absente"

echo "=== 9. LIVRAISON ==="
T=$(get_token $JAR_A $BASE/admin/vehicles/view.php?id=$VID)
CODE=$(curl -s -b $JAR_A -c $JAR_A -o /dev/null -w '%{http_code}' -X POST $BASE/admin/vehicles/view.php?id=$VID \
  --data-urlencode "csrf_token=$T" --data-urlencode "action=deliver" \
  --data-urlencode "comment=Client satisfait, vÃ©hicule restituÃ©")
check "$CODE" "302" "POST livraison (302)"
ST=$(mysql -u root -h 127.0.0.1 --default-character-set=utf8mb4 -N garage_db1 -e "SELECT status FROM vehicles WHERE id=$VID")
check "$ST" "livre" "vÃ©hicule passÃ© Ã  LIVRÃ‰"
HIST=$(mysql -u root -h 127.0.0.1 --default-character-set=utf8mb4 -N garage_db1 -N -e "SELECT GROUP_CONCAT(status SEPARATOR 'â†’') FROM (SELECT status FROM vehicle_status_history WHERE vehicle_id=$VID ORDER BY id) t")
check "$HIST" "initialâ†’en_coursâ†’en_reparationâ†’repareâ†’livre" "chaÃ®ne d'historique complÃ¨te"

echo "=== 10. POINTAGE ==="
# entrÃ©e
T=$(get_token $JAR_M $BASE/mechanic/attendance.php)
CODE=$(curl -s -b $JAR_M -c $JAR_M -o /dev/null -w '%{http_code}' -X POST $BASE/mechanic/attendance.php \
  --data-urlencode "csrf_token=$T" --data-urlencode "clock_type=IN")
check "$CODE" "302" "pointage ENTRÃ‰E (302)"
N=$(mysql -u root -h 127.0.0.1 --default-character-set=utf8mb4 -N garage_db1 -e "SELECT COUNT(*) FROM attendance WHERE user_id=2 AND clock_type='IN' AND day_date=CURDATE()")
check "$N" "1" "entrÃ©e enregistrÃ©e"
# 2e entrÃ©e refusÃ©e
T=$(get_token $JAR_M $BASE/mechanic/attendance.php)
curl -s -b $JAR_M -c $JAR_M -o /tmp/double.html -X POST $BASE/mechanic/attendance.php \
  --data-urlencode "csrf_token=$T" --data-urlencode "clock_type=IN"
curl -s -b $JAR_M $BASE/mechanic/attendance.php > /tmp/double2.html
grep -q "dÃ©jÃ  entrÃ©" /tmp/double2.html && ok "double entrÃ©e refusÃ©e (message)" || ko "double entrÃ©e" "message absent"
N=$(mysql -u root -h 127.0.0.1 --default-character-set=utf8mb4 -N garage_db1 -e "SELECT COUNT(*) FROM attendance WHERE user_id=2 AND clock_type='IN' AND day_date=CURDATE()")
check "$N" "1" "aucune 2e entrÃ©e en base"
# sortie
T=$(get_token $JAR_M $BASE/mechanic/attendance.php)
curl -s -b $JAR_M -c $JAR_M -o /dev/null -X POST $BASE/mechanic/attendance.php \
  --data-urlencode "csrf_token=$T" --data-urlencode "clock_type=OUT"
N=$(mysql -u root -h 127.0.0.1 --default-character-set=utf8mb4 -N garage_db1 -e "SELECT COUNT(*) FROM attendance WHERE user_id=2 AND day_date=CURDATE()")
check "$N" "2" "sortie enregistrÃ©e (2 pointages)"
# re-entrÃ©e
T=$(get_token $JAR_M $BASE/mechanic/attendance.php)
curl -s -b $JAR_M -c $JAR_M -o /dev/null -X POST $BASE/mechanic/attendance.php \
  --data-urlencode "csrf_token=$T" --data-urlencode "clock_type=IN"
# tableau hebdo admin
curl -s -b $JAR_A "$BASE/admin/attendance/index.php?mechanic_id=2" > /tmp/att.html
grep -q "Mohamed Trabelsi" /tmp/att.html && ok "tableau hebdo admin OK" || ko "tableau hebdo" "mÃ©canicien absent"
grep -q "Total travaillÃ©" /tmp/att.html && ok "colonnes heures prÃ©sentes" || ko "colonnes heures" "absentes"
# anomalie forcÃ©e : sortie sans entrÃ©e (ahmed, hier)
mysql -u root -h 127.0.0.1 --default-character-set=utf8mb4 garage_db1 -e "INSERT INTO attendance (user_id, mechanic_id, clock_type, clocked_at, day_date) VALUES (3, 2, 'OUT', DATE_SUB(NOW(), INTERVAL 1 DAY), DATE_SUB(CURDATE(), INTERVAL 1 DAY))"
curl -s -b $JAR_A "$BASE/admin/attendance/anomalies.php?period=month" > /tmp/anom.html
grep -q "Sortie sans entrÃ©e" /tmp/anom.html && ok "anomalie 'sortie sans entrÃ©e' dÃ©tectÃ©e" || ko "anomalie" "non dÃ©tectÃ©e"

echo "=== 11. DEVIS ==="
T=$(get_token $JAR_A $BASE/admin/devis/form.php)
CODE=$(curl -s -b $JAR_A -c $JAR_A -o /dev/null -w '%{http_code}' -X POST $BASE/admin/devis/form.php \
  --data-urlencode "csrf_token=$T" --data-urlencode "quote_number=DEV-2026-0001" \
  --data-urlencode "client_id=$CID" --data-urlencode "vehicle_id=$VID" \
  --data-urlencode "devis_date=2026-09-16" --data-urlencode "valid_until=2026-10-01" \
  --data-urlencode "doc_lang=fr" --data-urlencode "notes=Main-d'Å“uvre comprise pour les piÃ¨ces ci-dessus" \
  --data-urlencode "item_desc[1]=Remplacement amortisseurs avant (piÃ¨ces)" \
  --data-urlencode "item_qty[1]=2" --data-urlencode "item_price[1]=120" \
  --data-urlencode "item_discount[1]=10" --data-urlencode "item_tva[1]=19" \
  --data-urlencode "item_desc[2]=Main-d'Å“uvre suspension" \
  --data-urlencode "item_qty[2]=3" --data-urlencode "item_price[2]=90" \
  --data-urlencode "item_discount[2]=0" --data-urlencode "item_tva[2]=19")
check "$CODE" "302" "POST nouveau devis (302)"
DID=$(mysql -u root -h 127.0.0.1 --default-character-set=utf8mb4 -N garage_db1 -e "SELECT id FROM devis WHERE quote_number='DEV-2026-0001'")
[ -n "$DID" ] && ok "devis crÃ©Ã©" || ko "devis" "introuvable"
# Ligne 1: 2x120=240, remise 10% => 216 HT, TVA 19% => 41.04 ; Ligne 2: 3x90=270 HT, TVA 51.30
# Total HT = 486, TVA = 92.34, TTC = 578.34
HT=$(mysql -u root -h 127.0.0.1 --default-character-set=utf8mb4 -N garage_db1 -e "SELECT total_ht FROM devis WHERE id=$DID")
TVA=$(mysql -u root -h 127.0.0.1 --default-character-set=utf8mb4 -N garage_db1 -e "SELECT total_tva FROM devis WHERE id=$DID")
TTC=$(mysql -u root -h 127.0.0.1 --default-character-set=utf8mb4 -N garage_db1 -e "SELECT total_ttc FROM devis WHERE id=$DID")
check "$HT" "486.00" "total HT calculÃ© (486.00)"
check "$TVA" "92.34" "total TVA calculÃ© (92.34)"
check "$TTC" "578.34" "total TTC calculÃ© (578.34)"
# doublon de numÃ©ro
T=$(get_token $JAR_A $BASE/admin/devis/form.php)
curl -s -b $JAR_A -c $JAR_A -o /tmp/dupdev.html -X POST $BASE/admin/devis/form.php \
  --data-urlencode "csrf_token=$T" --data-urlencode "quote_number=DEV-2026-0001" \
  --data-urlencode "client_id=$CID" --data-urlencode "vehicle_id=" \
  --data-urlencode "devis_date=2026-09-16" --data-urlencode "valid_until=" \
  --data-urlencode "doc_lang=fr" --data-urlencode "notes=" \
  --data-urlencode "item_desc[1]=Test" --data-urlencode "item_qty[1]=1" \
  --data-urlencode "item_price[1]=10" --data-urlencode "item_discount[1]=0" --data-urlencode "item_tva[1]=19"
grep -q "existe dÃ©jÃ " /tmp/dupdev.html && ok "numÃ©ro de devis en doublon refusÃ©" || ko "doublon devis" "refus absent"
# PDF
curl -s -b $JAR_A -o /tmp/devis.pdf -w '%{http_code}' $BASE/admin/devis/pdf.php?id=$DID > /tmp/c
check "$(cat /tmp/c)" "200" "GET PDF devis (200)"
head -c 5 /tmp/devis.pdf | grep -q "%PDF" && ok "PDF devis valide (%PDF)" || ko "PDF devis" "en-tÃªte invalide"
# xref offset check
php -r '
$d = file_get_contents("/tmp/devis.pdf");
$pos = strrpos($d, "startxref");
$num = trim(substr($d, $pos + 9, 20));
$xrefPos = (int) preg_split("/\s+/", $num)[0];
$chunk = substr($d, $xrefPos, 10);
if (strpos($chunk, "xref") === 0) { echo "xref OK\n"; } else { echo "xref KO: ".var_export($chunk,true)."\n"; exit(1); }
// vÃ©rifie les offsets des objets
$objcount = 0;
foreach (explode("\n", substr($d, $xrefPos)) as $i => $line) {
  if ($i === 0) continue;
  if ($i === 1) continue;
  if (preg_match("/^(\d{10}) 00000 n/", $line, $m)) {
    $off = (int)$m[1];
    $expect = substr($d, $off, 20);
    if (!preg_match("/^\d+ 0 obj/", $expect)) { echo "OFFSET KO obj @$off: $expect\n"; exit(1); }
    $objcount++;
  }
}
echo "objects vÃ©rifiÃ©s: $objcount\n";
' && ok "structure PDF (xref + offsets) valide" || ko "structure PDF" "vÃ©rification Ã©chouÃ©e"

echo "=== 12. ENVOI E-MAIL DEVIS ==="
T=$(get_token $JAR_A $BASE/admin/devis/view.php?id=$DID)
CODE=$(curl -s -b $JAR_A -c $JAR_A -o /dev/null -w '%{http_code}' -X POST $BASE/admin/devis/send.php \
  --data-urlencode "csrf_token=$T" --data-urlencode "devis_id=$DID" \
  --data-urlencode "to_email=sami.brown@prosauto.ca" \
  --data-urlencode "message=Bonjour, veuillez trouver le devis.")
check "$CODE" "302" "POST envoi e-mail devis (302, sans crash)"
curl -s -b $JAR_A $BASE/admin/devis/view.php?id=$DID > /tmp/emailres.html
if grep -q "E-mail envoyÃ©" /tmp/emailres.html; then ok "e-mail envoyÃ© (mail() OK)";
elif grep -q "Ã©chouÃ©" /tmp/emailres.html; then ok "mail() indispo dans le sandbox â†’ message d'erreur propre + lien PDF";
else ko "e-mail" "aucun rÃ©sultat"; fi

echo "=== 13. FACTURE DEPUIS DEVIS ==="
T=$(get_token $JAR_A "$BASE/admin/factures/form.php?devis_id=$DID")
CODE=$(curl -s -b $JAR_A -c $JAR_A -o /dev/null -w '%{http_code}' -X POST $BASE/admin/factures/form.php?devis_id=$DID \
  --data-urlencode "csrf_token=$T" --data-urlencode "invoice_number=FAC-2026-0001" \
  --data-urlencode "client_id=$CID" --data-urlencode "vehicle_id=$VID" \
  --data-urlencode "invoice_date=2026-09-16" --data-urlencode "due_date=2026-10-01" \
  --data-urlencode "doc_lang=fr" --data-urlencode "notes=Paiement Ã  15 jours" \
  --data-urlencode "item_desc[1]=Remplacement amortisseurs avant (piÃ¨ces)" \
  --data-urlencode "item_qty[1]=2" --data-urlencode "item_price[1]=120" \
  --data-urlencode "item_discount[1]=10" --data-urlencode "item_tva[1]=19" \
  --data-urlencode "item_desc[2]=Main-d'Å“uvre suspension" \
  --data-urlencode "item_qty[2]=3" --data-urlencode "item_price[2]=90" \
  --data-urlencode "item_discount[2]=0" --data-urlencode "item_tva[2]=19")
check "$CODE" "302" "POST facture depuis devis (302)"
FID=$(mysql -u root -h 127.0.0.1 --default-character-set=utf8mb4 -N garage_db1 -e "SELECT id FROM factures WHERE invoice_number='FAC-2026-0001'")
[ -n "$FID" ] && ok "facture crÃ©Ã©e" || ko "facture" "introuvable"
TTCF=$(mysql -u root -h 127.0.0.1 --default-character-set=utf8mb4 -N garage_db1 -e "SELECT total_ttc FROM factures WHERE id=$FID")
check "$TTCF" "578.34" "total facture = total devis (578.34)"
DS=$(mysql -u root -h 127.0.0.1 --default-character-set=utf8mb4 -N garage_db1 -e "SELECT status FROM devis WHERE id=$DID")
check "$DS" "facture" "devis marquÃ© comme facturÃ©"
# PDF facture
curl -s -b $JAR_A -o /tmp/facture.pdf -w '%{http_code}' $BASE/admin/factures/pdf.php?id=$FID > /tmp/c
check "$(cat /tmp/c)" "200" "GET PDF facture (200)"
head -c 5 /tmp/facture.pdf | grep -q "%PDF" && ok "PDF facture valide" || ko "PDF facture" "invalide"
# encaissement partiel puis solde
T=$(get_token $JAR_A $BASE/admin/factures/view.php?id=$FID)
curl -s -b $JAR_A -c $JAR_A -o /dev/null -X POST $BASE/admin/factures/view.php?id=$FID \
  --data-urlencode "csrf_token=$T" --data-urlencode "action=payment" --data-urlencode "amount=200"
ST=$(mysql -u root -h 127.0.0.1 --default-character-set=utf8mb4 -N garage_db1 -e "SELECT status FROM factures WHERE id=$FID")
check "$ST" "brouillon" "encaissement partiel -> statut inchange (pas de partiellement payee)"
T=$(get_token $JAR_A $BASE/admin/factures/view.php?id=$FID)
curl -s -b $JAR_A -c $JAR_A -o /dev/null -X POST $BASE/admin/factures/view.php?id=$FID \
  --data-urlencode "csrf_token=$T" --data-urlencode "action=payment" --data-urlencode "amount=378.34"
ST=$(mysql -u root -h 127.0.0.1 --default-character-set=utf8mb4 -N garage_db1 -e "SELECT status FROM factures WHERE id=$FID")
check "$ST" "payee" "solde encaissÃ© â†’ payee"

echo "=== 13b. FACTURE DIRECTE (sans devis) ==="
T=$(get_token $JAR_A $BASE/admin/factures/form.php)
CODE=$(curl -s -b $JAR_A -c $JAR_A -o /dev/null -w '%{http_code}' -X POST $BASE/admin/factures/form.php \
  --data-urlencode "csrf_token=$T" --data-urlencode "invoice_number=FAC-2026-0002" \
  --data-urlencode "client_id=$CID" --data-urlencode "vehicle_id=$VID" \
  --data-urlencode "invoice_date=2026-09-16" --data-urlencode "due_date=2026-09-30" \
  --data-urlencode "doc_lang=fr" --data-urlencode "notes=" \
  --data-urlencode "item_desc[1]=Vidange complÃ¨te" \
  --data-urlencode "item_qty[1]=1" --data-urlencode "item_price[1]=150" \
  --data-urlencode "item_discount[1]=0" --data-urlencode "item_tva[1]=19")
check "$CODE" "302" "POST facture directe (302)"
FID2=$(mysql -u root -h 127.0.0.1 --default-character-set=utf8mb4 -N garage_db1 -e "SELECT id FROM factures WHERE invoice_number='FAC-2026-0002'")
[ -n "$FID2" ] && ok "facture directe crÃ©Ã©e" || ko "facture directe" "introuvable"
TTC2=$(mysql -u root -h 127.0.0.1 --default-character-set=utf8mb4 -N garage_db1 -e "SELECT total_ttc FROM factures WHERE id=$FID2")
check "$TTC2" "178.50" "total facture directe (150 x 1.19 = 178.50)"

echo "=== 14. RECHERCHE GLOBALE ==="
curl -s -b $JAR_A "$BASE/search.php?q=ABC%20123" > /tmp/sr.html
grep -q "ABC 123" /tmp/sr.html && ok "recherche par immatriculation" || ko "recherche immat" "introuvable"
curl -s -b $JAR_A "$BASE/search.php?q=Brown" > /tmp/sr2.html
grep -q "Brown" /tmp/sr2.html && ok "recherche par nom client" || ko "recherche client" "introuvable"

echo "=== 15. BILINGUE FR/EN ==="
curl -s -b $JAR_A -c $JAR_A -o /dev/null -w '%{http_code}' "$BASE/admin/dashboard.php?lang=en" > /tmp/c
check "$(cat /tmp/c)" "303" "switch langue (303 redirect)"
curl -s -b $JAR_A $BASE/admin/dashboard.php > /tmp/dash_en.html
grep -q "Dashboard" /tmp/dash_en.html && ok "dashboard en anglais" || ko "dashboard EN" "anglais absent"
grep -q "Present today" /tmp/dash_en.html && ok "carte 'Present today' EN" || ko "carte EN" "absente"
# language persist via cookie
grep -q "garage_lang" /tmp/jar_admin.txt && ok "cookie de langue enregistrÃ©" || ko "cookie langue" "absent"
curl -s -b $JAR_A -c $JAR_A -o /dev/null "$BASE/admin/dashboard.php?lang=fr"
curl -s -b $JAR_A $BASE/admin/dashboard.php > /tmp/dash_fr.html
grep -q "Tableau de bord" /tmp/dash_fr.html && ok "retour en franÃ§ais" || ko "retour FR" "Ã©chouÃ©"

echo "=== 16. CSRF ==="
CODE=$(curl -s -b $JAR_A -o /dev/null -w '%{http_code}' -X POST $BASE/admin/clients/form.php \
  --data-urlencode "civility=Monsieur" --data-urlencode "last_name=Hacker" --data-urlencode "first_name=X")
check "$CODE" "403" "POST sans token CSRF â†’ 403"
TOKEN_FAKE="aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa"
CODE=$(curl -s -b $JAR_A -o /dev/null -w '%{http_code}' -X POST $BASE/admin/clients/form.php \
  --data-urlencode "csrf_token=$TOKEN_FAKE" --data-urlencode "civility=Monsieur" --data-urlencode "last_name=Hacker" --data-urlencode "first_name=X")
check "$CODE" "403" "POST avec token invalide â†’ 403"

echo "=== 17. SÃ‰CURITÃ‰ DIVERS ==="
# accÃ¨s direct Ã  includes
CODE=$(curl -s -o /dev/null -w '%{http_code}' $BASE/includes/functions.php)
[ "$CODE" = "404" ] || [ "$CODE" = "403" ] && ok "accÃ¨s direct includes/ bloquÃ© ($CODE)" || ko "includes direct" "code $CODE"
# XSS dans client â†’ affichÃ© Ã©chappÃ© (dÃ©jÃ  testÃ©)
# journal d'activitÃ©
curl -s -b $JAR_A $BASE/admin/logs/index.php > /tmp/logs.html
grep -q "client_created" /tmp/logs.html && ok "journal contient les actions" || ko "journal" "actions absentes"
# export CSV pointage
curl -s -b $JAR_A "$BASE/admin/attendance/history.php?export=csv&from=2026-09-01&to=2026-09-30" > /tmp/att.csv
head -1 /tmp/att.csv | grep -q "MÃ©canicien" && ok "export CSV pointage OK" || ko "CSV pointage" "en-tÃªte absent"
# export CSV journal
curl -s -b $JAR_A "$BASE/admin/logs/index.php?export=csv" > /tmp/logs.csv
head -1 /tmp/logs.csv | grep -q "Utilisateur" && ok "export CSV journal OK" || ko "CSV journal" "en-tÃªte absent"
# notifications
curl -s -b $JAR_A $BASE/notifications/index.php > /tmp/notif.html
grep -q "RÃ©paration terminÃ©e" /tmp/notif.html && ok "notification visible dans la page" || ko "page notif" "introuvable"

echo "=== 18. PARAMÃˆTRES ==="
T=$(get_token $JAR_A $BASE/admin/settings.php)
curl -s -b $JAR_A -c $JAR_A -o /dev/null -X POST $BASE/admin/settings.php \
  --data-urlencode "csrf_token=$T" --data-urlencode "company_name=PROS AUTO" \
  --data-urlencode "company_address=245 King Street West, Toronto, ON M5V 3K2, Canada" \
  --data-urlencode "company_phone=+1 (416) 555-0147" --data-urlencode "company_email=info@prosauto.ca" \
  --data-urlencode "company_web=www.prosauto.ca" --data-urlencode "company_tax_id=HST 123456789 RT0001" \
  --data-urlencode "email_from=info@prosauto.ca" --data-urlencode "default_tva=13" \
  --data-urlencode "expected_in_time=08:00" --data-urlencode "normal_daily_hours=8" \
  --data-urlencode "break_threshold_minutes=30" --data-urlencode "currency=CAD"
V=$(mysql -u root -h 127.0.0.1 --default-character-set=utf8mb4 -N garage_db1 -e "SELECT setting_value FROM settings WHERE setting_key='company_name'")
check "$V" "PROS AUTO" "paramÃ¨tres sauvegardÃ©s"

echo ""
echo "================================================"
echo "RÃ‰SULTATS : $PASS rÃ©ussis, $FAIL Ã©chouÃ©s"
echo "================================================"

