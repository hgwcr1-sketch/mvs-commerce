#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

usage() {
    echo "Uso: restore-company.sh --dir EXPORT_DIR --db TARGET_DB [--host H] [--port P] [--user U] [--report FILE]" >&2
}

DIR=""
DB=""
HOST="${PGHOST:-127.0.0.1}"
PORT="${PGPORT:-5432}"
DBUSER="${PGUSER:-postgres}"
REPORT=""

while [[ $# -gt 0 ]]; do
    case "$1" in
        --dir) DIR="$2"; shift 2 ;;
        --db) DB="$2"; shift 2 ;;
        --host) HOST="$2"; shift 2 ;;
        --port) PORT="$2"; shift 2 ;;
        --user) DBUSER="$2"; shift 2 ;;
        --report) REPORT="$2"; shift 2 ;;
        *) echo "Argumento desconocido: $1" >&2; usage; exit 2 ;;
    esac
done

[[ -n "$DIR" && -d "$DIR" && -f "$DIR/manifest.json" ]] || { echo "dir/manifest invalido" >&2; usage; exit 2; }
[[ -n "$DB" ]] || { echo "db obligatoria" >&2; usage; exit 2; }
if [[ "$DB" != *_company_restore_test ]]; then
    echo "RECHAZADO: la BD destino debe terminar en _company_restore_test" >&2; exit 3
fi
if [[ "$DB" == "mvscommerce_production" ]]; then
    echo "RECHAZADO: mvscommerce_production nunca es destino" >&2; exit 3
fi

command -v jq >/dev/null || { echo "jq no disponible" >&2; exit 2; }
command -v python3 >/dev/null || { echo "python3 no disponible" >&2; exit 2; }
command -v psql >/dev/null || { echo "psql no disponible" >&2; exit 2; }

CID="$(jq -r '.company_id' "$DIR/manifest.json")"
[[ "$CID" =~ ^[0-9]+$ ]] || { echo "company_id invalido en manifiesto" >&2; exit 2; }

echo "== Validar manifiesto (SHA256, conteos CSV, uniformidad company_id)"
bash "$SCRIPT_DIR/validate-company-export.sh" --dir "$DIR"

psqlq() {
    if [[ -n "${PSQL_WRAPPER:-}" ]]; then
        $PSQL_WRAPPER psql -X -q -At -F '|' -v ON_ERROR_STOP=1 -h "$HOST" -p "$PORT" -U "$DBUSER" -d "$DB" -c "$1"
    else
        psql -X -q -At -F '|' -v ON_ERROR_STOP=1 -h "$HOST" -p "$PORT" -U "$DBUSER" -d "$DB" -c "$1"
    fi
}

echo "== Verificar BD destino ${DB} (esquema provisionado y sin datos de las tablas del export)"
tables_total="$(psqlq "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = 'public' AND table_type = 'BASE TABLE'")"
[[ "$tables_total" =~ ^[0-9]+$ && "$tables_total" -ge 1 ]] || { echo "La BD destino no tiene esquema; ejecute las migraciones primero" >&2; exit 4; }

while IFS= read -r tb; do
    exists="$(psqlq "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = 'public' AND table_name = '${tb}'")"
    [[ "$exists" == "1" ]] || { echo "Falta la tabla destino: $tb" >&2; exit 4; }
    live="$(psqlq "SELECT COUNT(*) FROM \"${tb}\"")"
    [[ "$live" == "0" ]] || { echo "RECHAZADO: la tabla destino $tb no está vacía (filas=$live)" >&2; exit 4; }
done < <(jq -r '.tables[].table' "$DIR/manifest.json")

echo "== Orden de carga por dependencias FK"
EDGES="$(psqlq "
SELECT clc.relname, clp.relname
FROM pg_constraint con
JOIN pg_class clc ON clc.oid = con.conrelid
JOIN pg_class clp ON clp.oid = con.confrelid
JOIN pg_namespace nsp ON nsp.oid = clc.relnamespace
WHERE con.contype = 'f' AND nsp.nspname = 'public'
  AND clp.relnamespace = 'public'::regnamespace")"
ORDER="$(jq -r '.tables[].table' "$DIR/manifest.json" | python3 -c '
import sys
tables = [l.strip() for l in sys.stdin if l.strip()]
edges = []
for line in sys.argv[1].splitlines():
    if "|" in line:
        child, parent = line.split("|")[:2]
        edges.append((child, parent))
deps = {t: set() for t in tables}
ts = set(tables)
for child, parent in edges:
    if child in ts and parent in ts and child != parent:
        deps[child].add(parent)
order, seen = [], set()
while len(order) < len(tables):
    progress = False
    for t in tables:
        if t not in seen and deps[t] <= seen:
            order.append(t); seen.add(t); progress = True
    if not progress:
        order = order + [t for t in tables if t not in seen]
        break
print("\n".join(order))
' "$EDGES")"

echo "== Cargar datos"
while IFS= read -r tb; do
    [[ -n "$tb" ]] || continue
    cols="$(python3 - "$DIR/$tb.csv.gz" <<'PY'
import csv, gzip, sys
with gzip.open(sys.argv[1], "rt", newline="") as fh:
    headers = next(csv.reader(fh))
print(",".join('"' + h.replace('"', '""') + '"' for h in headers))
PY
)"
    if [[ -n "${PSQL_WRAPPER:-}" ]]; then
        gzip -dc "$DIR/$tb.csv.gz" | $PSQL_WRAPPER psql -X -q -At -v ON_ERROR_STOP=1 -h "$HOST" -p "$PORT" -U "$DBUSER" -d "$DB" -c "\copy \"${tb}\" (${cols}) FROM STDIN WITH (FORMAT csv, HEADER true)"
    else
        gzip -dc "$DIR/$tb.csv.gz" | psql -X -q -At -v ON_ERROR_STOP=1 -h "$HOST" -p "$PORT" -U "$DBUSER" -d "$DB" -c "\copy \"${tb}\" (${cols}) FROM STDIN WITH (FORMAT csv, HEADER true)"
    fi
    echo "   cargada: $tb"
done <<<"$ORDER"

echo "== Validar conteos contra manifiesto"
while IFS=$'\t' read -r tb rows; do
    live="$(psqlq "SELECT COUNT(*) FROM \"${tb}\"")"
    [[ "$live" == "$rows" ]] || { echo "Conteo distinto en $tb: vivo=$live manifiesto=$rows" >&2; exit 5; }
done < <(jq -r '.tables[] | [.table, (.rows|tostring)] | @tsv' "$DIR/manifest.json")

echo "== Validar FK (huérfanos entre tablas del export = 0)"
FK_OUT="$(psqlq "
SELECT clc.relname, clp.relname,
       array_to_string(ARRAY(SELECT a.attname FROM unnest(con.conkey) WITH ORDINALITY AS k(attnum, ord)
                             JOIN pg_attribute a ON a.attrelid = con.conrelid AND a.attnum = k.attnum ORDER BY k.ord), ','),
       array_to_string(ARRAY(SELECT a.attname FROM unnest(con.confkey) WITH ORDINALITY AS k(attnum, ord)
                             JOIN pg_attribute a ON a.attrelid = con.confrelid AND a.attnum = k.attnum ORDER BY k.ord), ',')
FROM pg_constraint con
JOIN pg_class clc ON clc.oid = con.conrelid
JOIN pg_class clp ON clp.oid = con.confrelid
JOIN pg_namespace nsp ON nsp.oid = clc.relnamespace
WHERE con.contype = 'f' AND nsp.nspname = 'public'")"
IN_SET="$(jq -r '.tables[].table' "$DIR/manifest.json" | paste -sd, -)"
FK_CHECKS=0
while IFS='|' read -r child parent ccols pcols; do
    [[ -n "${child:-}" ]] || continue
    echo ",${IN_SET}," | grep -q ",${child}," || continue
    echo ",${IN_SET}," | grep -q ",${parent}," || continue
    conds=""
    notnull=""
    IFS=',' read -ra ca <<<"$ccols"
    IFS=',' read -ra pa <<<"$pcols"
    for i in "${!ca[@]}"; do
        conds+=" AND c.\"${ca[$i]}\" = p.\"${pa[$i]}\""
        notnull+=" AND c.\"${ca[$i]}\" IS NOT NULL"
    done
    sql="SELECT COUNT(*) FROM \"${child}\" c LEFT JOIN \"${parent}\" p ON ${conds# AND } WHERE TRUE${notnull} AND p.\"${pa[0]}\" IS NULL"
    orphans="$(psqlq "$sql")"
    [[ "$orphans" == "0" ]] || { echo "FK huérfanos en ${child} -> ${parent}: $orphans" >&2; exit 6; }
    FK_CHECKS=$((FK_CHECKS + 1))
done <<<"$FK_OUT"

echo "== Validar 0 filas de otras empresas (fuga)"
CROSS=0
while IFS=$'\t' read -r tb kind; do
    if [[ "$kind" == "direct" ]]; then
        other="$(psqlq "SELECT COUNT(*) FROM \"${tb}\" WHERE company_id <> ${CID}")"
    elif [[ "$kind" == "self" ]]; then
        other="$(psqlq "SELECT COUNT(*) FROM \"${tb}\" WHERE id <> ${CID}")"
    else
        other="0"
    fi
    [[ "$other" == "0" ]] || { echo "FUGA: $tb tiene $other filas de otra empresa" >&2; exit 7; }
    CROSS=$((CROSS + other))
done < <(jq -r '.tables[] | [.table, .kind] | @tsv' "$DIR/manifest.json")

echo "== Ajustar secuencias"
SEQ_FIXED=0
while IFS='|' read -r tb col; do
    [[ -n "${tb:-}" && -n "${col:-}" ]] || continue
    psqlq "SELECT setval(pg_get_serial_sequence('${tb}', '${col}'), COALESCE((SELECT MAX(\"${col}\") FROM \"${tb}\"), 1), (SELECT MAX(\"${col}\") IS NOT NULL FROM \"${tb}\"))" >/dev/null
    SEQ_FIXED=$((SEQ_FIXED + 1))
done < <(while IFS= read -r tb; do
    psqlq "SELECT '${tb}' || '|' || a.attname FROM pg_attribute a WHERE a.attrelid = '\"${tb}\"'::regclass AND a.attnum > 0 AND NOT a.attisdropped AND pg_get_serial_sequence('${tb}', a.attname) IS NOT NULL"
done < <(jq -r '.tables[].table' "$DIR/manifest.json"))

ROWS_TOTAL="$(jq '[.tables[].rows] | add' "$DIR/manifest.json")"
TABLES_TOTAL="$(jq '.tables | length' "$DIR/manifest.json")"
created="$(date -u +%Y-%m-%dT%H:%M:%SZ)"

if [[ -n "$REPORT" ]]; then
    jq -n --arg created "$created" --argjson company "$CID" --arg db "$DB" \
        --argjson tables "$TABLES_TOTAL" --argjson rows "$ROWS_TOTAL" \
        --argjson fk "$FK_CHECKS" --argjson seq "$SEQ_FIXED" --argjson cross "$CROSS" \
        '{format: 1, validated_at: $created, company_id: $company, database: $db,
          tables: $tables, rows_total: $rows, fk_checks: $fk, sequences_fixed: $seq,
          cross_company_rows: $cross, status: "ok"}' > "$REPORT"
fi

echo "RESTORE COMPANY OK: empresa ${CID} -> ${DB} | tablas=${TABLES_TOTAL} filas=${ROWS_TOTAL} fk_ok=${FK_CHECKS} seq=${SEQ_FIXED} cross_rows=${CROSS}"
