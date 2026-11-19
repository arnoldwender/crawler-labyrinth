#!/usr/bin/env bash
#
# tests/run-mutations.sh — checks that tests/test-suite.php defends each invariant listed in tests/mutations.php.
#
# Usage: bash tests/run-mutations.sh
# Exit:  0 when the unmutated control passes and every mutation applies and turns the suite red.

set -uo pipefail

ROOT=$(cd "$(dirname "$0")/.." && pwd)
WORK=$(mktemp -d "${TMPDIR:-/tmp}/cl-mutations.XXXXXX")
trap 'rm -rf "$WORK"' EXIT

RESULTS_GREEN='^  TEST SUITE RESULTS: [1-9][0-9]* PASSED \| 0 FAILED$'
RESULTS_RED='^  TEST SUITE RESULTS: [0-9]+ PASSED \| [1-9][0-9]* FAILED$'

has_line() {
  grep -Eq "$1" <<<"$2"
}

# Self-check: ensure large output before results line still matches.
selfcheck_out="  TEST SUITE RESULTS: 1 PASSED | 1 FAILED"$'\n'"$(head -c 1048576 /dev/zero | tr '\0' 'x')"
if ! has_line "$RESULTS_RED" "$selfcheck_out"; then
  echo "RUNNER SELF-CHECK FAILED"
  exit 1
fi
unset selfcheck_out

fresh_copy() {
  rm -rf "$WORK/repo" && mkdir -p "$WORK/repo"
  local expected copied
  expected=$( (cd "$ROOT" && git ls-files -z --cached --others --exclude-standard) | tr -dc '\0' | wc -c | tr -d ' ' )
  if ! (cd "$ROOT" && git ls-files -z --cached --others --exclude-standard | tar -c -f - --null -T -) \
       | (cd "$WORK/repo" && tar -x -f -); then
    echo "COPY FAILED into $WORK/repo" >&2
    return 1
  fi
  copied=$(find "$WORK/repo" \( -type f -o -type l \) | wc -l | tr -d ' ')
  if [ "$copied" -lt "$expected" ]; then
    echo "COPY SHORT: copied $copied of $expected file(s)" >&2
    return 1
  fi
}

count=$(php -r 'echo count(require $argv[1]);' "$ROOT/tests/mutations.php")

fresh_copy || exit 1
control_out=$(cd "$WORK/repo" && php tests/test-suite.php 2>&1)
control_exit=$?
if [ "$control_exit" -ne 0 ] || ! has_line "$RESULTS_GREEN" "$control_out"; then
  echo "CONTROL FAILED (exit $control_exit)"
  printf '%s\n' "$control_out" | grep -E '\[FAIL\]' | head -5
  exit 1
fi
echo "CONTROL      unmutated copy green ($(printf '%s\n' "$control_out" | grep -Eo '[0-9]+ PASSED'))"

failed=0
for ((i = 0; i < count; i++)); do
  fresh_copy || { failed=1; continue; }
  desc=$(php -r '
    $m = (require $argv[1])[(int) $argv[2]];
    echo $m[0];
    foreach ($m[1] as [$file, $search, $replace]) {
      $path = $argv[3] . "/" . $file;
      $src  = (string) file_get_contents($path);
      if (substr_count($src, $search) !== 1) {
        fwrite(STDERR, $file . ": search occurs " . substr_count($src, $search) . " times");
        exit(3);
      }
      file_put_contents($path, str_replace($search, $replace, $src));
    }
  ' "$ROOT/tests/mutations.php" "$i" "$WORK/repo" 2>"$WORK/apply.err")
  apply_exit=$?
  if [ "$apply_exit" -ne 0 ]; then
    echo "NOT APPLIED  $desc ($(cat "$WORK/apply.err"))"
    failed=1
    continue
  fi

  out=$(cd "$WORK/repo" && php tests/test-suite.php 2>&1)
  if has_line '(PHP )?Parse error' "$out"; then
    echo "NO RESULT    $desc: parse error"
    failed=1
  elif has_line "$RESULTS_RED" "$out"; then
    echo "RED          $desc"
  elif has_line "$RESULTS_GREEN" "$out"; then
    echo "SURVIVED     $desc"
    failed=1
  else
    echo "NO RESULT    $desc: no results line"
    failed=1
  fi
done

exit "$failed"
