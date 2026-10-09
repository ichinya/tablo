#!/usr/bin/env bash
# Bash-specific hidden prompts. Disable tracing before handling any input.
set +x
reset_password=''
reset_confirmation=''
trap 'unset reset_password reset_confirmation' EXIT
trap 'exit 130' INT
trap 'exit 143' TERM
cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." || exit 1
case "${1:-Native}" in
  Native) reset_command=(php bin/admin-password.php --password-stdin) ;;
  Docker) reset_command=(docker compose exec -T --user www-data tablo php bin/admin-password.php --password-stdin) ;;
  DockerStopped) reset_command=(docker compose run --rm --no-deps -T --user www-data --entrypoint php tablo bin/admin-password.php --password-stdin) ;;
  *) printf 'Invalid mode. Use Native, Docker or DockerStopped.\n' >&2; exit 2 ;;
esac
if (( $# > 1 )); then exit 2; fi
if ! IFS= read -r -s -p 'New password: ' reset_password </dev/tty; then exit 2; fi
printf '\n' >&2
if ! IFS= read -r -s -p 'Confirm password: ' reset_confirmation </dev/tty; then exit 2; fi
printf '\n' >&2
printf '%s\n%s\n' "$reset_password" "$reset_confirmation" | "${reset_command[@]}"
reset_exit=$?
exit "$reset_exit"
