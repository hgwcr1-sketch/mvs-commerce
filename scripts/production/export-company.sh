#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
MAP="${SCRIPT_DIR}/company-tables.json"

usage() {
    echo "Uso: export-company.sh --company-id N --db NAME --out DIR [--host H] [--port P] [--user U] [--allow-live-db]" >&2
}

CID=""
DB=""
HOST="${PGHOST:-127.0.0.1}"
PORT="${PGPORT:-5432}"
DBUSER="${PGUSER:-postgres}"
OUT=""
ALLOW_LIVE=0

while [[ $# -gt 0 ]]; do
    case "$1" in
        --company-id) CID="$2"; shift 2 ;;
        --db) DB="$2"; shift 2 ;;
        --host) HOST="$2"; shift 2 ;;
        --port) PORT="$2"; shift 2 ;;
        --user) DBUSER="$2"; shift 2 ;;
        --out) OUT="$2"; shift 2 ;;
        --allow-live-db) ALLOW_LIVE=1; shift ;;
        *) echo "Argumento desconocido: $1" >&2; usage; exit 2 ;;
    esac
done

[[ -n "$CID" && "$CID" =~ ^[0-9]+$ ]] || { echo "company-id invalido" >&2; usage; exit 2; }
[[ -n "$DB" ]] || { echo "db obligatoria" >&2; usage; exit 2; }
[[ -n "$OUT" ]] || { echo "out obligatorio" >&2; usage; exit 2; }
[[ -f "$MAP" ]] || { echo "No existe el mapa: $MAP" >&2; exit 2; }

if [[ "$DB" == "mvscommerce_production" && "$ALLOW_LIVE" -ne 1 ]]; then
    echo "RECHAZADO: export contra mvscommerce_production requiere --allow-live-db" >&2; exit 3
fi
if [[ "$ALLOW_LIVE" -ne 1 && ! "$DB" =~ _test$ ]]; then
    echo "RECHAZADO: la base debe terminar en _test salvo --allow-live-db" >&2; exit 3
fi

command -v psql >/dev/null || { echo "psql no disponible" >&2; exit 2; }
command -v jq >/dev/null || { echo "jq no disponible" >&2; exit 2; }
command -v python3 >/dev/null || { echo "python3 no disponible" >&2; exit 2; }
command -v gzip >/dev/null || { echo "gzip no disponible" >&2; exit 2; }

mkdir -p "$OUT"
OUT="$(cd "$OUT" && pwd)"

PSQL_CMD=(psql -X -q -At -v ON_ERROR_STOP=1 -h "$HOST" -p "$PORT" -U "$DBUSER" -d "$DB")
psqlq() {
    if [[ -n "${PSQL_WRAPPER:-}" ]]; then
        $PSQL_WRAPPER "${PSQL_CMD[@]}" -c "$1"
    else
        "${PSQL_CMD[@]}" -c "$1"
    fi
}

company_rows="$(psqlq "SELECT COUNT(*) FROM companies WHERE id = ${CID}")" || { echo "No se pudo consultar companies" >&2; exit 4; }
[[ "$company_rows" == "1" ]] || { echo "La empresa ${CID} no existe en ${DB}" >&2; exit 4; }

mapfile -t TABLES < <(psqlq "SELECT table_name FROM information_schema.tables WHERE table_schema = 'public' AND table_type = 'BASE TABLE' ORDER BY 1")
[[ ${#TABLES[@]} -gt 0 ]] || { echo "Sin tablas en ${DB}" >&2; exit 4; }

EXCLUDED=()
UNCLASSIFIED=()
ENTRIES="${OUT}/.entries.jsonl"
: > "$ENTRIES"
exported=0

for tb in "${TABLES[@]}"; do
    kind=""
    sql=""
    if jq -e --arg t "$tb" '.exclude_tables // [] | index($t)' "$MAP" >/dev/null; then
        EXCLUDED+=("$tb")
        continue
    elif jq -e --arg t "$tb" '.self_filter[$t]' "$MAP" >/dev/null; then
        filter="$(jq -r --arg t "$tb" '.self_filter[$t]' "$MAP" | sed "s/{{ID}}/${CID}/g")"
        sql="SELECT * FROM ${tb} WHERE ${filter}"
        kind="self"
    else
        has_company="$(psqlq "SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = 'public' AND table_name = '${tb}' AND column_name = 'company_id'")"
        if [[ "$has_company" == "1" ]]; then
            sql="SELECT * FROM ${tb} WHERE company_id = ${CID}"
            kind="direct"
        elif jq -e --arg t "$tb" '.indirect[$t]' "$MAP" >/dev/null; then
            sql="$(jq -r --arg t "$tb" '.indirect[$t]' "$MAP" | sed "s/{{ID}}/${CID}/g")"
            kind="sql"
        else
            UNCLASSIFIED+=("$tb")
            continue
        fi
    fi

    rows="$(psqlq "SELECT COUNT(*) FROM ( ${sql} ) AS mvs_count")"
    file="${tb}.csv.gz"
    psqlq "COPY ( ${sql} ) TO STDOUT WITH (FORMAT csv, HEADER true)" | gzip -n -9 > "${OUT}/${file}"

    csv_rows="$(python3 - "$OUT/$file" <<'PY'
import csv, gzip, sys
with gzip.open(sys.argv[1], "rt", newline="") as fh:
    print(sum(1 for _ in csv.reader(fh)) - 1)
PY
)"
    if [[ "$csv_rows" != "$rows" ]]; then
        echo "Conteo distinto en ${tb}: sql=${rows} csv=${csv_rows}" >&2
        exit 5
    fi

    sha="$(sha256sum "${OUT}/${file}" | awk '{print $1}')"
    jq -n --arg t "$tb" --arg k "$kind" --argjson r "$rows" --arg f "$file" --arg s "$sha" --arg sql "$sql" \
        '{table: $t, kind: $k, rows: $r, file: $f, sha256: $s, sql: $sql}' >> "$ENTRIES"
    exported=$((exported + 1))
done

if [[ ${#UNCLASSIFIED[@]} -gt 0 ]]; then
    echo "Tablas sin clasificar (agregar a company-tables.json):" >&2
    printf '  %s\n' "${UNCLASSIFIED[@]}" >&2
    exit 6
fi

created="$(date -u +%Y-%m-%dT%H:%M:%SZ)"
pgv="$(psqlq "SHOW server_version")"
gitc=""
if command -v git >/dev/null && git -C "$SCRIPT_DIR" rev-parse HEAD >/dev/null 2>&1; then
    gitc="$(git -C "$SCRIPT_DIR" rev-parse HEAD)"
fi

jq -s --arg created "$created" --argjson company "$CID" --arg db "$DB" --arg pg "$pgv" \
    --arg git "$gitc" --argjson exported "$exported" \
    '{format: 1, created_at: $created, company_id: $company, database: $db, pg_version: $pg,
      git_commit: $git, retention_years: 5, exported_tables: $exported, tables: .}' \
    "$ENTRIES" > "${OUT}/manifest.json"
rm -f "$ENTRIES"

echo "Export empresa ${CID}: ${exported} tablas, ${#EXCLUDED[@]} excluidas -> ${OUT}/manifest.json"
