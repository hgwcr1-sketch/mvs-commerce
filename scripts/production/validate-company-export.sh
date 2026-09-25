#!/usr/bin/env bash
set -euo pipefail

usage() {
    echo "Uso: validate-company-export.sh --dir DIR [--db NAME --host H --port P --user U]" >&2
}

DIR=""
DB=""
HOST="${PGHOST:-127.0.0.1}"
PORT="${PGPORT:-5432}"
DBUSER="${PGUSER:-postgres}"

while [[ $# -gt 0 ]]; do
    case "$1" in
        --dir) DIR="$2"; shift 2 ;;
        --db) DB="$2"; shift 2 ;;
        --host) HOST="$2"; shift 2 ;;
        --port) PORT="$2"; shift 2 ;;
        --user) DBUSER="$2"; shift 2 ;;
        *) echo "Argumento desconocido: $1" >&2; usage; exit 2 ;;
    esac
done

[[ -n "$DIR" && -d "$DIR" ]] || { echo "dir invalido" >&2; usage; exit 2; }
command -v jq >/dev/null || { echo "jq no disponible" >&2; exit 2; }
command -v python3 >/dev/null || { echo "python3 no disponible" >&2; exit 2; }
[[ -f "$DIR/manifest.json" ]] || { echo "Falta manifest.json en $DIR" >&2; exit 3; }

format="$(jq -r '.format' "$DIR/manifest.json")"
[[ "$format" == "1" ]] || { echo "format de manifiesto no soportado: $format" >&2; exit 3; }
CID="$(jq -r '.company_id' "$DIR/manifest.json")"
[[ "$CID" =~ ^[0-9]+$ ]] || { echo "company_id invalido en manifiesto" >&2; exit 3; }

while IFS=$'\t' read -r file sha; do
    path="$DIR/$file"
    [[ -f "$path" ]] || { echo "Falta archivo del manifiesto: $file" >&2; exit 4; }
    actual="$(sha256sum "$path" | awk '{print $1}')"
    [[ "$actual" == "$sha" ]] || { echo "SHA256 distinto en $file" >&2; exit 4; }
done < <(jq -r '.tables[] | [.file, .sha256] | @tsv' "$DIR/manifest.json")

python3 - "$DIR/manifest.json" <<'PY'
import csv, gzip, json, os, sys

manifest = json.load(open(sys.argv[1], encoding="utf-8"))
company_id = str(manifest["company_id"])
base = os.path.dirname(os.path.abspath(sys.argv[1]))
errors = []
for entry in manifest["tables"]:
    with gzip.open(os.path.join(base, entry["file"]), "rt", newline="") as fh:
        reader = csv.DictReader(fh)
        headers = reader.fieldnames or []
        rows = list(reader)
    if len(rows) != entry["rows"]:
        errors.append(f"{entry['table']}: filas csv={len(rows)} manifiesto={entry['rows']}")
    if entry["kind"] in ("direct", "self"):
        if "company_id" not in headers and entry["kind"] == "direct":
            errors.append(f"{entry['table']}: sin columna company_id")
        for idx, row in enumerate(rows, start=2):
            if entry["kind"] == "direct" and row.get("company_id") != company_id:
                errors.append(f"{entry['table']} linea {idx}: company_id={row.get('company_id')} != {company_id}")
                break
            if entry["kind"] == "self" and row.get("id") != company_id:
                errors.append(f"{entry['table']} linea {idx}: id={row.get('id')} != {company_id}")
                break
    elif entry["kind"] == "sql" and "company_id" in headers:
        errors.append(f"{entry['table']}: tabla indirecta no debe traer company_id directo")
if errors:
    print("\n".join(errors), file=sys.stderr)
    sys.exit(5)
print(f"CSV verificados: {len(manifest['tables'])} tablas, company_id={company_id} uniforme")
PY

if [[ -n "$DB" ]]; then
    command -v psql >/dev/null || { echo "psql no disponible" >&2; exit 2; }
    while IFS=$'\t' read -r table rows sql; do
        if [[ -n "${PSQL_WRAPPER:-}" ]]; then
            live="$($PSQL_WRAPPER psql -X -q -At -v ON_ERROR_STOP=1 -h "$HOST" -p "$PORT" -U "$DBUSER" -d "$DB" -c "SELECT COUNT(*) FROM ( ${sql} ) AS mvs_count")"
        else
            live="$(psql -X -q -At -v ON_ERROR_STOP=1 -h "$HOST" -p "$PORT" -U "$DBUSER" -d "$DB" -c "SELECT COUNT(*) FROM ( ${sql} ) AS mvs_count")"
        fi
        [[ "$live" == "$rows" ]] || { echo "Conteo vivo distinto en ${table}: vivo=${live} manifiesto=${rows}" >&2; exit 6; }
    done < <(jq -r '.tables[] | [.table, (.rows|tostring), .sql] | @tsv' "$DIR/manifest.json")
    echo "Conteos vivos verificados contra la base $DB"
fi

echo "VALIDACION OK: $DIR (empresa $CID)"
