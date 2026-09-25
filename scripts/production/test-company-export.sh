#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
DB="${TEST_DB:-mvscommerce_export_ab_test}"
PSQL_WRAPPER="${PSQL_WRAPPER:-sudo -u postgres}"

if [[ "$DB" != *_test || "$DB" == "mvscommerce_production" ]]; then
    echo "RECHAZADO: TEST_DB debe terminar en _test y no ser mvscommerce_production" >&2
    exit 3
fi

p() {
    $PSQL_WRAPPER psql -X -q -At -v ON_ERROR_STOP=1 -d "$DB" -c "$1"
}

echo "== Crear base scratch ${DB}"
$PSQL_WRAPPER dropdb --if-exists "$DB"
$PSQL_WRAPPER createdb "$DB"

p "
CREATE TABLE companies (id bigserial PRIMARY KEY, name text NOT NULL);
CREATE TABLE branches (id bigserial PRIMARY KEY, company_id bigint NOT NULL REFERENCES companies(id), name text NOT NULL, code text);
CREATE TABLE users (id bigserial PRIMARY KEY, name text NOT NULL, email text UNIQUE NOT NULL);
CREATE TABLE company_user (company_id bigint NOT NULL REFERENCES companies(id), user_id bigint NOT NULL REFERENCES users(id), PRIMARY KEY (company_id, user_id));
CREATE TABLE branch_user (branch_id bigint NOT NULL REFERENCES branches(id), user_id bigint NOT NULL REFERENCES users(id), PRIMARY KEY (branch_id, user_id));
CREATE TABLE customers (id bigserial PRIMARY KEY, company_id bigint NOT NULL REFERENCES companies(id), name text NOT NULL, email text UNIQUE NOT NULL);
CREATE TABLE sales (id bigserial PRIMARY KEY, company_id bigint NOT NULL REFERENCES companies(id), sale_number text NOT NULL, customer_id bigint REFERENCES customers(id));
CREATE TABLE sale_items (id bigserial PRIMARY KEY, sale_id bigint NOT NULL REFERENCES sales(id), description text NOT NULL, qty int NOT NULL);
CREATE TABLE jobs (id bigserial PRIMARY KEY, queue text, payload text);
CREATE TABLE countries (id bigserial PRIMARY KEY, name text NOT NULL);
"

echo "== Sembrar Empresa A y Empresa B"
p "
INSERT INTO companies (id, name) VALUES (980001, 'ALPHA-COMPANY-A-MARKER'), (980002, 'BETA-COMPANY-B-MARKER');
INSERT INTO branches (company_id, name, code) VALUES (980001, 'Sucursal A', 'SA'), (980002, 'Sucursal B', 'SB');
INSERT INTO users (name, email) VALUES ('User A', 'a-user@example.test'), ('User B', 'b-user@example.test');
INSERT INTO company_user (company_id, user_id) SELECT 980001, id FROM users WHERE email = 'a-user@example.test';
INSERT INTO company_user (company_id, user_id) SELECT 980002, id FROM users WHERE email = 'b-user@example.test';
INSERT INTO branch_user (branch_id, user_id) SELECT b.id, u.id FROM branches b, users u WHERE b.company_id = 980001 AND u.email = 'a-user@example.test';
INSERT INTO customers (company_id, name, email) VALUES (980001, 'Cliente A', 'a-client@example.test'), (980002, 'Cliente B', 'leak-sensor-b@example.test');
INSERT INTO sales (company_id, sale_number, customer_id) SELECT 980001, 'A-SALE-001', id FROM customers WHERE email = 'a-client@example.test';
INSERT INTO sales (company_id, sale_number, customer_id) SELECT 980001, 'A-SALE-002', id FROM customers WHERE email = 'a-client@example.test';
INSERT INTO sales (company_id, sale_number, customer_id) SELECT 980002, 'B-LEAK-SALE-001', id FROM customers WHERE email = 'leak-sensor-b@example.test';
INSERT INTO sale_items (sale_id, description, qty) SELECT id, 'Item A1', 1 FROM sales WHERE sale_number = 'A-SALE-001';
INSERT INTO sale_items (sale_id, description, qty) SELECT id, 'Item A2', 2 FROM sales WHERE sale_number = 'A-SALE-002';
INSERT INTO sale_items (sale_id, description, qty) SELECT id, 'Item A3', 3 FROM sales WHERE sale_number = 'A-SALE-002';
INSERT INTO sale_items (sale_id, description, qty) SELECT id, 'Item B1 LEAK', 1 FROM sales WHERE sale_number = 'B-LEAK-SALE-001';
INSERT INTO jobs (queue, payload) VALUES ('default', 'B-MARKER-BETA-JOB');
INSERT INTO countries (name) VALUES ('BETA-COMPANY-B-MARKER');
"

OUT="$(mktemp -d)"
trap 'rm -rf "$OUT"; $PSQL_WRAPPER dropdb --if-exists "$DB"' EXIT

echo "== Exportar Empresa A (980001)"
PSQL_WRAPPER="$PSQL_WRAPPER" bash "$SCRIPT_DIR/export-company.sh" \
    --company-id 980001 --db "$DB" --host /var/run/postgresql --user postgres --out "$OUT/export"

echo "== Validar manifiesto/SHA256/conteos/uniformidad"
PSQL_WRAPPER="$PSQL_WRAPPER" bash "$SCRIPT_DIR/validate-company-export.sh" \
    --dir "$OUT/export" --db "$DB" --host /var/run/postgresql --user postgres

fail=0

check_absent() {
    if compgen -G "$OUT/export/$1.csv.gz" >/dev/null; then
        echo "FALLO: $1 no debio exportarse (tabla excluida)"; fail=1
    else
        echo "OK ausente excluida: $1"
    fi
}
check_absent "jobs"
check_absent "countries"

echo "== Anti-fuga A->B: buscar marcadores de B en la exportacion de A"
LEAKS="$(for f in "$OUT"/export/*.csv.gz; do
    gzip -dc "$f"
done | grep -c -E 'BETA-COMPANY-B-MARKER|leak-sensor-b@example\.test|B-LEAK-SALE-001|Item B1 LEAK|b-user@example\.test' || true)"
if [[ "$LEAKS" != "0" ]]; then
    echo "FALLO: ${LEAKS} coincidencias de Empresa B en exportacion de A"; fail=1
else
    echo "OK 0 filas/marcadores de B en exportacion de A"
fi

echo "== Presencia de marcadores de A"
AFULL="$(for f in "$OUT"/export/*.csv.gz; do gzip -dc "$f"; done)"
for marker in "ALPHA-COMPANY-A-MARKER" "A-SALE-001" "A-SALE-002" "a-client@example.test" "Item A1"; do
    if ! grep -q "$marker" <<<"$AFULL"; then
        echo "FALLO: falta marcador de A: $marker"; fail=1
    fi
done
echo "OK marcadores de A presentes"

echo "== Conteos esperados desde manifiesto"
expect() {
    got="$(jq -r --arg t "$1" '.tables[] | select(.table == $t) | .rows' "$OUT/export/manifest.json")"
    if [[ "$got" != "$2" ]]; then
        echo "FALLO: $1 rows=$got esperado=$2"; fail=1
    else
        echo "OK $1 rows=$got"
    fi
}
expect "companies" "1"
expect "sales" "2"
expect "sale_items" "3"
expect "customers" "1"
expect "users" "1"
expect "company_user" "1"
expect "branch_user" "1"
expect "branches" "1"

echo "== B sigue intacta en la base (no se toco)"
bcount="$(p "SELECT COUNT(*) FROM companies WHERE id = 980002")"
if [[ "$bcount" != "1" ]]; then
    echo "FALLO: Empresa B alterada"; fail=1
else
    echo "OK Empresa B intacta en BD scratch"
fi

if [[ "$fail" != "0" ]]; then
    echo "TEST EMPRESA A/B: FALLO"
    exit 7
fi
echo "TEST EMPRESA A/B: PASS (0 filas cruzadas, conteos correctos)"
