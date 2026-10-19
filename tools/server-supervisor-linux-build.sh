#!/bin/sh
# AUTHOR-ONLY until a fresh isolated build is explicitly authorized. No installs.
# Run from a read-only reviewed source root; /out is the single fresh output mount.
# COMPILER_IMAGE_DIGEST is the approved immutable linux/amd64 image manifest.
set -eu
umask 077
test "$(uname -m)" = x86_64
test "$#" = 0
test -d /out
test ! -e /out/server-supervisor-linux
test ! -e /out/server-supervisor-linux-controls
case "${COMPILER_IMAGE_DIGEST-}" in sha256:*) ;; *) exit 125 ;; esac
IMAGE_HEX=${COMPILER_IMAGE_DIGEST#sha256:}
test "${#IMAGE_HEX}" = 64
case "$IMAGE_HEX" in *[!0-9a-f]*|'') exit 125 ;; esac
CC_PATH=$(command -v gcc)
LD_PATH=$(gcc -print-prog-name=ld)
case "$LD_PATH" in /*) ;; *) LD_PATH=$(command -v "$LD_PATH") ;; esac
LIBC_ARCHIVE=$(gcc -print-file-name=libc.a)
test -f "$CC_PATH"
test -f "$LD_PATH"
test -f "$LIBC_ARCHIVE"
test "$LIBC_ARCHIVE" != libc.a
gcc --version > /out/compiler-version.txt
"$LD_PATH" --version > /out/linker-version.txt
sha256sum tools/server-supervisor-linux.c tools/server-supervisor-linux-controls.c \
  tools/server-supervisor-linux-build.sh > /out/source-SHA256SUMS
# No shared loader/library can change the reviewed helper after its binary pin.
gcc -std=c11 -O2 -Wall -Wextra -Werror -fno-strict-aliasing -fstack-protector-strong \
  -D_FORTIFY_SOURCE=2 -static -Wl,-z,noexecstack -Wl,--build-id=none \
  -o /out/server-supervisor-linux tools/server-supervisor-linux.c
gcc -std=c11 -O2 -Wall -Wextra -Werror -fno-strict-aliasing -fstack-protector-strong \
  -D_FORTIFY_SOURCE=2 -static -pthread -Wl,-z,noexecstack -Wl,--build-id=none \
  -o /out/server-supervisor-linux-controls tools/server-supervisor-linux-controls.c
hash() { sha256sum "$1" | cut -d ' ' -f 1; }
printf '{"schema":"tablo/linux-adapter-build/v1","policy":"tgid-seccomp-ptrace-x64-v2","target":"linux-x86_64","sourceSha256":"%s","recipeSha256":"%s","binarySha256":"%s","compilerImageDigest":"%s","compilerSha256":"%s","linkerSha256":"%s","libcArchiveSha256":"%s"}\n' \
  "$(hash tools/server-supervisor-linux.c)" "$(hash tools/server-supervisor-linux-build.sh)" \
  "$(hash /out/server-supervisor-linux)" "$COMPILER_IMAGE_DIGEST" "$(hash "$CC_PATH")" \
  "$(hash "$LD_PATH")" "$(hash "$LIBC_ARCHIVE")" > /out/build.json
sha256sum tools/server-supervisor-linux.c tools/server-supervisor-linux-controls.c \
  tools/server-supervisor-linux-build.sh /out/server-supervisor-linux \
  /out/server-supervisor-linux-controls /out/build.json > /out/SHA256SUMS
# This is build provenance only. Never emit a qualification receipt here.
