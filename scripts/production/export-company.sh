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
PLAN_TABLES=()
PLAN_KIND=()
PLAN_SQL=()
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

    PLAN_TABLES+=("$tb")
    PLAN_KIND+=("$kind")
    PLAN_SQL+=("$sql")
done

if [[ ${#UNCLASSIFIED[@]} -gt 0 ]]; then
    echo "Tablas sin clasificar (agregar a company-tables.json):" >&2
    printf '  %s\n' "${UNCLASSIFIED[@]}" >&2
    exit 6
fi

# Users miembros de la empresa + users referenciados por FK desde las filas exportadas.
USERS_SQL="$(jq -r '.indirect["users"] // empty' "$MAP" | sed "s/{{ID}}/${CID}/g")"
[[ -n "$USERS_SQL" ]] || { echo "Sin regla indirecta para users en company-tables.json" >&2; exit 6; }

REF_TMP="${OUT}/.users-ref.txt"
: > "$REF_TMP"

REF_PAIRS="$(psqlq "
SELECT DISTINCT table_name || '|' || column_name FROM (
    SELECT table_name, column_name
    FROM information_schema.columns
    WHERE table_schema = 'public'
      AND data_type IN ('bigint', 'integer', 'smallint')
      AND (column_name ~ '(^user_id$|_user_id$|_by$)' OR column_name IN ('actor_id', 'assigned_to'))
    UNION
    SELECT c.relname, a.attname
    FROM pg_constraint con
    JOIN pg_class c ON c.oid = con.conrelid
    JOIN pg_class rc ON rc.oid = con.confrelid
    JOIN pg_namespace n ON n.oid = c.relnamespace
    JOIN LATERAL unnest(con.conkey) WITH ORDINALITY AS k(attnum, ord) ON true
    JOIN pg_attribute a ON a.attrelid = con.conrelid AND a.attnum = k.attnum
    WHERE con.contype = 'f' AND n.nspname = 'public' AND rc.relname = 'users'
) src ORDER BY 1" | tr -d '\r')"

for i in "${!PLAN_TABLES[@]}"; do
    tb="${PLAN_TABLES[$i]}"
    sql="${PLAN_SQL[$i]}"
    cols="$(printf '%s\n' "$REF_PAIRS" | awk -F'|' -v t="$tb" '$1 == t {print $2}')"
    [[ -n "$cols" ]] || continue
    union_sql=""
    while IFS= read -r col; do
        [[ -n "$col" ]] || continue
        [[ -n "$union_sql" ]] && union_sql="${union_sql} UNION ALL "
        union_sql="${union_sql}SELECT x.\"${col}\" AS ref_id FROM ( ${sql} ) x WHERE x.\"${col}\" IS NOT NULL"
    done <<< "$cols"
    psqlq "SELECT ref_id FROM ( ${union_sql} ) refs WHERE EXISTS (SELECT 1 FROM users u WHERE u.id = refs.ref_id)" | tr -d '\r' >> "$REF_TMP"
done

mapfile -t REF_IDS < <(sort -u "$REF_TMP")
rm -f "$REF_TMP"

EXTRA_IDS=()
CROSS_USERS=()
HAS_PADMIN="$(psqlq "SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = 'public' AND table_name = 'users' AND column_name = 'is_platform_admin'" | tr -d '\r')"
PADMIN_SEL="false"
[[ "$HAS_PADMIN" == "1" ]] && PADMIN_SEL="u.is_platform_admin"

if [[ ${#REF_IDS[@]} -gt 0 ]]; then
    for ((start = 0; start < ${#REF_IDS[@]}; start += 500)); do
        chunk=("${REF_IDS[@]:start:500}")
        ids_csv="$(IFS=,; echo "${chunk[*]}")"
        ref_rows="$(psqlq "
SELECT u.id, ${PADMIN_SEL},
       EXISTS(SELECT 1 FROM company_user cu WHERE cu.user_id = u.id AND cu.company_id = ${CID}),
       EXISTS(SELECT 1 FROM branch_user bu JOIN branches b ON b.id = bu.branch_id WHERE bu.user_id = u.id AND b.company_id = ${CID}),
       EXISTS(SELECT 1 FROM company_user cu WHERE cu.user_id = u.id AND cu.company_id <> ${CID}),
       EXISTS(SELECT 1 FROM branch_user bu JOIN branches b ON b.id = bu.branch_id WHERE bu.user_id = u.id AND b.company_id <> ${CID})
FROM users u
WHERE u.id IN (${ids_csv})
ORDER BY u.id" | tr -d '\r')"
        while IFS='|' read -r uid padmin in_a in_a_br in_o in_o_br; do
            [[ -n "${uid:-}" ]] || continue
            if [[ "$in_a" == "t" || "$in_a_br" == "t" ]]; then
                continue
            fi
            if [[ "$padmin" == "t" || "$padmin" == "true" ]]; then
                EXTRA_IDS+=("$uid")
                continue
            fi
            if [[ "$in_o" == "t" || "$in_o_br" == "t" ]]; then
                CROSS_USERS+=("$uid")
                continue
            fi
            EXTRA_IDS+=("$uid")
        done <<< "$ref_rows"
    done
fi

if [[ ${#CROSS_USERS[@]} -gt 0 ]]; then
    cross_csv="$(IFS=,; echo "${CROSS_USERS[*]}")"
    echo "RECHAZADO: users referenciados por filas exportadas pertenecen a otra empresa tenant." >&2
    echo "La empresa ${CID} no se exporta; revisar vinculacion de usuarios antes de repetir." >&2
    psqlq "
SELECT u.id || ' | ' || COALESCE(u.email, '') || ' | empresas=' || COALESCE((
    SELECT string_agg(DISTINCT x.company_id::text, ',')
    FROM (
        SELECT cu.company_id FROM company_user cu WHERE cu.user_id = u.id AND cu.company_id <> ${CID}
        UNION
        SELECT b.company_id FROM branch_user bu JOIN branches b ON b.id = bu.branch_id WHERE bu.user_id = u.id AND b.company_id <> ${CID}
    ) x
), 'ninguna')
FROM users u WHERE u.id IN (${cross_csv}) ORDER BY u.id" | tr -d '\r' >&2
    exit 7
fi

if [[ ${#EXTRA_IDS[@]} -gt 0 ]]; then
    extra_csv="$(IFS=,; echo "${EXTRA_IDS[*]}")"
    USERS_SQL="${USERS_SQL} UNION (SELECT u.* FROM users u WHERE u.id IN (${extra_csv}))"
fi

for i in "${!PLAN_TABLES[@]}"; do
    if [[ "${PLAN_TABLES[$i]}" == "users" ]]; then
        PLAN_SQL[$i]="$USERS_SQL"
    fi
done

echo "Users referenciados: ${#REF_IDS[@]} ids unicos, ${#EXTRA_IDS[@]} adicionales incluidos, ${#CROSS_USERS[@]} cross-company"

for i in "${!PLAN_TABLES[@]}"; do
    tb="${PLAN_TABLES[$i]}"
    kind="${PLAN_KIND[$i]}"
    sql="${PLAN_SQL[$i]}"

    rows="$(psqlq "SELECT COUNT(*) FROM ( ${sql} ) AS mvs_count")"
    file="${tb}.csv.gz"
    psqlq "COPY ( ${sql} ) TO STDOUT WITH (FORMAT csv, HEADER true)" | gzip -n -9 > "${OUT}/${file}"

    csv_rows="$(python3 - "$OUT/$file" <<'PY'
import csv, gzip, sys
csv.field_size_limit(sys.maxsize)
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
