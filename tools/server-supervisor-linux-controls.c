// Finite neutral controls. Build/run only with a separate explicit authorization.
// No sockets, database, process discovery, supplied-PID signaling or dependencies
// beyond the compiler image's pinned libc/pthread implementation.
#define _GNU_SOURCE
#include <errno.h>
#include <fcntl.h>
#include <linux/io_uring.h>
#include <pthread.h>
#include <poll.h>
#include <sched.h>
#include <signal.h>
#include <spawn.h>
#include <stdio.h>
#include <stdlib.h>
#include <string.h>
#include <sys/prctl.h>
#include <sys/syscall.h>
#include <sys/socket.h>
#include <sys/uio.h>
#include <stdint.h>
#include <sys/wait.h>
#include <time.h>
#include <unistd.h>

static int release_fd[2];
static pid_t children[8];
static unsigned count;
static const char *self;
static void require(int condition) { if (!condition) _exit(99); }
static void say(const char *s) { size_t n = strlen(s); require(write(1, s, n) == (ssize_t)n); }
static void pause_ms(unsigned ms) {
    struct timespec t = { .tv_sec = ms / 1000, .tv_nsec = (long)(ms % 1000) * 1000000 };
    while (nanosleep(&t, &t) && errno == EINTR) {}
}
static void init(void) { require(pipe2(release_fd, O_CLOEXEC) == 0); }
static void hold(void) {
    close(release_fd[1]);
    char byte; ssize_t n;
    do { n = read(release_fd[0], &byte, 1); } while (n < 0 && errno == EINTR);
    _exit(n == 0 ? 0 : 98);
}
static void add(void) {
    pid_t child = fork(); require(child >= 0);
    if (!child) hold();
    require(count < 8); children[count++] = child;
}
static void release(void) {
    close(release_fd[1]); close(release_fd[0]);
    for (unsigned i = 0; i < count; i++) {
        int status; pid_t r;
        do { r = waitpid(children[i], &status, 0); } while (r < 0 && errno == EINTR);
        require(r == children[i] && WIFEXITED(status) && WEXITSTATUS(status) == 0);
    }
}
static int quota(unsigned cap, int overflow) {
    init(); for (unsigned i = 1; i < cap; i++) add();
    if (overflow) {
        errno = 0; pid_t child = fork();
        if (!child) { say("FORBIDDEN_OVERFLOW_CHILD\n"); _exit(97); }
        require(child == -1 && errno == EAGAIN);
        puts("DENIED_BEFORE_CHILD; root deliberately returns zero");
    }
    release(); return 0;
}
static int thread_pipe[2];
static void *thread_hold(void *arg) {
    (void)arg; char byte; ssize_t n;
    do { n = read(thread_pipe[0], &byte, 1); } while (n < 0 && errno == EINTR);
    require(n == 0); return NULL;
}
static int threads(void) {
    pthread_t t[4];
    require(!pipe2(thread_pipe, O_CLOEXEC));
    for (unsigned i = 0; i < 4; i++) require(!pthread_create(&t[i], NULL, thread_hold, NULL));
    int r = quota(8, 0);
    close(thread_pipe[1]);
    for (unsigned i = 0; i < 4; i++) require(!pthread_join(t[i], NULL));
    close(thread_pipe[0]); return r;
}
static void *thread_fork(void *arg) { (void)arg; return (void *)(long)quota(8, 0); }
static void *thread_exec(void *arg) {
    (void)arg;
    char *const args[] = { (char *)self, "exit23", NULL };
    execv(self, args); _exit(99);
}
static pthread_barrier_t barrier;
static void *concurrent_fork(void *arg) {
    (void)arg; int r = pthread_barrier_wait(&barrier); require(r == 0 || r == PTHREAD_BARRIER_SERIAL_THREAD);
    pid_t child = fork();
    if (!child) hold();
    int rejected = child < 0; if (rejected) require(errno == EAGAIN);
    r = pthread_barrier_wait(&barrier); require(r == 0 || r == PTHREAD_BARRIER_SERIAL_THREAD);
    if (r == PTHREAD_BARRIER_SERIAL_THREAD) close(release_fd[1]);
    if (rejected) return (void *)1;
    int status; require(waitpid(child, &status, 0) == child && WIFEXITED(status) && WEXITSTATUS(status) == 0);
    return NULL;
}
static int concurrent(void) {
    pthread_t t[12]; unsigned rejected = 0;
    init();
    require(!pthread_barrier_init(&barrier, NULL, 12));
    for (unsigned i = 0; i < 12; i++) require(!pthread_create(&t[i], NULL, concurrent_fork, NULL));
    for (unsigned i = 0; i < 12; i++) { void *value; require(!pthread_join(t[i], &value)); rejected += value != NULL; }
    close(release_fd[0]); require(rejected > 0); puts("CONCURRENT_DENIAL_ROOT_ZERO"); return 0;
}
static int vfork_exec(void) {
    char *const args[] = { (char *)self, "exit0", NULL };
    pid_t child = vfork();
    if (!child) {
        execv(self, args); _exit(99);
    }
    require(child > 0);
    int status; require(waitpid(child, &status, 0) == child && WIFEXITED(status) && WEXITSTATUS(status) == 0); return 0;
}
static int short_lived(void) {
    for (unsigned i = 0; i < 64; i++) {
        pid_t child = fork(); require(child >= 0); if (!child) _exit(0);
        int status; require(waitpid(child, &status, 0) == child && WIFEXITED(status) && WEXITSTATUS(status) == 0);
    }
    return 0;
}
static int spawn_exec(void) {
    extern char **environ;
    char *const args[] = { (char *)self, "exit0", NULL };
    pid_t child; int status;
    require(posix_spawn(&child, self, NULL, NULL, args, environ) == 0);
    require(waitpid(child, &status, 0) == child && WIFEXITED(status) && WEXITSTATUS(status) == 0);
    return 0;
}
static int failed_fork(void) {
    errno = 0;
    // Invalid legacy clone: CLONE_SIGHAND requires CLONE_VM. This is an admitted
    // process reservation whose kernel failure must release only that slot.
    long result = syscall(SYS_clone, CLONE_SIGHAND | SIGCHLD, NULL, NULL, NULL, 0);
    require(result == -1 && errno == EINVAL);
    return quota(8, 0);
}
static int stdin_control(int synthetic) {
    unsigned total = 0; unsigned char bytes[4096];
    for (;;) {
        ssize_t n = read(0, bytes, sizeof(bytes));
        if (n < 0 && errno == EINTR) continue;
        require(n >= 0); if (!n) break;
        for (ssize_t i = 0; i < n; i++) require(bytes[i] == 0x5a);
        total += (unsigned)n;
    }
    require(total == (synthetic ? 65536U : 0U)); printf("INPUT_EOF %u\n", total); return 0;
}
static int orphan(int immediate) {
    int ready_pipe[2]; require(!pipe2(ready_pipe, O_CLOEXEC));
    pid_t child = fork(); require(child >= 0);
    if (!child) {
        close(ready_pipe[0]);
        require(setsid() > 0);
        pid_t grandchild = fork(); require(grandchild >= 0);
        if (grandchild) _exit(0);
        say("OWNED_SETSID_DESCENDANT\n");
        require(write(ready_pipe[1], "R", 1) == 1); close(ready_pipe[1]);
        for (;;) pause_ms(100);
    }
    close(ready_pipe[1]); char ready_byte;
    require(read(ready_pipe[0], &ready_byte, 1) == 1 && ready_byte == 'R'); close(ready_pipe[0]);
    if (immediate) return 0;
    for (;;) pause_ms(100);
}
static int escape(const char *mode) {
    errno = 0; long r;
    if (!strcmp(mode, "clone3")) {
        r = syscall(SYS_clone3, NULL, 0); require(r == -1 && errno == ENOSYS); return 0;
    }
    if (!strcmp(mode, "untraced")) r = syscall(SYS_clone, CLONE_UNTRACED | SIGCHLD, NULL, NULL, NULL, 0);
    else if (!strcmp(mode, "filter")) r = prctl(PR_SET_SECCOMP, 2, NULL);
    else if (!strcmp(mode, "namespace")) r = unshare(CLONE_NEWUSER);
    else if (!strcmp(mode, "ptrace")) r = syscall(SYS_ptrace, 0, 0, NULL, NULL);
    else if (!strcmp(mode, "x32")) { r = syscall(0x40000000L | SYS_getpid); _exit(96); }
    else if (!strcmp(mode, "i386")) { __asm__ volatile("int $0x80" : "=a"(r) : "a"(20) : "memory"); _exit(96); }
    else return 99;
    if (!r && !strcmp(mode, "untraced")) { say("FORBIDDEN_UNTRACED_CHILD\n"); _exit(97); }
    require(r == -1 && errno == EPERM); puts("ESCAPE_DENIED_ROOT_ZERO"); return 0;
}
static uint64_t monotonic(void) {
    struct timespec t; require(!clock_gettime(CLOCK_MONOTONIC, &t));
    return (uint64_t)t.tv_sec * UINT64_C(1000000000) + (uint64_t)t.tv_nsec;
}
static _Noreturn void death_tree(void) {
    pid_t child = fork(); require(child >= 0);
    if (!child) {
        require(setsid() > 0); pid_t grandchild = fork(); require(grandchild >= 0);
        if (!grandchild) say("TRACER_DEATH_TREE_READY\n");
        for (;;) pause_ms(100);
    }
    for (;;) pause_ms(100);
}
// Separate finite neutral observer, never a product sidecar. It receives kernel
// pidfds only from the pinned native child's private SOCK_SEQPACKET birth lane.
// After killing that exact native child it subreaps and waits each known TGID.
static int death_witness(const char *native, const char *control, const char *deadline) {
    uint64_t end = 0;
    for (const char *p = deadline; *p; p++) {
        if (*p < '0' || *p > '9' || end > (UINT64_MAX - 9) / 10) return 125;
        end = end * 10 + (unsigned)(*p - '0');
    }
    if (!end || end <= monotonic() + UINT64_C(1000000000)
        || end - monotonic() > UINT64_C(8000000000) || native[0] != '/' || control[0] != '/') return 125;
    int out = open("witness.stdout", O_RDWR | O_CREAT | O_EXCL | O_NOFOLLOW | O_CLOEXEC, 0600);
    int err = open("witness.stderr", O_RDWR | O_CREAT | O_EXCL | O_NOFOLLOW | O_CLOEXEC, 0600);
    int input = open("witness.input", O_RDWR | O_CREAT | O_EXCL | O_NOFOLLOW | O_CLOEXEC, 0600);
    int command[2], events[2], channel[2];
    if (out < 0 || err < 0 || input < 0 || pipe2(command, O_CLOEXEC) || pipe2(events, O_CLOEXEC)
        || socketpair(AF_UNIX, SOCK_SEQPACKET | SOCK_CLOEXEC | SOCK_NONBLOCK, 0, channel)
        || prctl(PR_SET_CHILD_SUBREAPER, 1, 0, 0, 0)) return 125;
    pid_t helper = fork(); if (helper < 0) return 125;
    if (!helper) {
        // Relocate first: dup2 into low FDs must not overwrite another source FD.
        int source[6] = { command[0], events[1], out, err, input, channel[1] }, high[6];
        for (unsigned i = 0; i < 6; i++) { high[i] = fcntl(source[i], F_DUPFD_CLOEXEC, 32); if (high[i] < 0) _exit(125); }
        int target[6] = { 0, 1, 3, 4, 5, 6 };
        for (unsigned i = 0; i < 6; i++) if (dup2(high[i], target[i]) < 0) _exit(125);
        if (syscall(SYS_close_range, 7U, ~0U, 0U)) _exit(125);
        char *const args[] = { (char *)native, "8", (char *)deadline, "0", "--witness=6", (char *)control, "death-tree", NULL };
        execv(native, args); _exit(125);
    }
    close(command[0]); close(events[1]); close(channel[1]); close(input);
    int helper_fd = (int)syscall(SYS_pidfd_open, helper, 0);
    if (fcntl(events[0], F_SETFL, O_NONBLOCK)) return 125;
    struct { pid_t pid; int fd, terminal; } owned[8] = {0};
    unsigned births_seen = 0, terminals = 0; int killed = 0, helper_terminal = 0, echild = 0, bad = helper_fd < 0;
    char log[4096] = {0}; size_t used = 0, parsed = 0;
    signal(SIGPIPE, SIG_IGN);
    while (monotonic() < end) {
        for (;;) {
            int record[2];
            union { struct cmsghdr align; unsigned char bytes[CMSG_SPACE(sizeof(int))]; } ancillary;
            memset(&ancillary, 0, sizeof(ancillary));
            struct iovec io = { .iov_base = record, .iov_len = sizeof(record) };
            struct msghdr m = { .msg_iov = &io, .msg_iovlen = 1, .msg_control = ancillary.bytes, .msg_controllen = sizeof(ancillary.bytes) };
            ssize_t n = recvmsg(channel[0], &m, MSG_DONTWAIT | MSG_CMSG_CLOEXEC);
            if (n < 0 && (errno == EAGAIN || errno == EINTR)) break;
            if (!n) break;
            struct cmsghdr *c = CMSG_FIRSTHDR(&m);
            if (n != (ssize_t)sizeof(record) || m.msg_flags & (MSG_CTRUNC | MSG_TRUNC) || !c
                || CMSG_NXTHDR(&m, c) || c->cmsg_level != SOL_SOCKET || c->cmsg_type != SCM_RIGHTS
                || c->cmsg_len != CMSG_LEN(sizeof(int)) || record[0] != 1 || record[1] <= 0 || births_seen >= 8) { bad = 1; break; }
            for (unsigned i = 0; i < births_seen; i++) if (owned[i].pid == record[1]) bad = 1;
            memcpy(&owned[births_seen].fd, CMSG_DATA(c), sizeof(int));
            owned[births_seen++].pid = record[1];
        }
        if (used < sizeof(log) - 1) {
            ssize_t n = read(events[0], log + used, sizeof(log) - 1 - used);
            if (n > 0) used += (size_t)n;
            else if (n < 0 && errno != EAGAIN && errno != EINTR) bad = 1;
        } else bad = 1;
        for (;;) {
            char *line = memchr(log + parsed, '\n', used - parsed); if (!line) break;
            size_t length = (size_t)(line - (log + parsed));
            if (length > 512) bad = 1;
            if (length > 6 && !memcmp(log + parsed, "READY ", 6)) {
                if (write(command[1], "LAUNCH\n", 7) != 7) bad = 1;
            }
            parsed += length + 1;
        }
        char marker[64] = {0}; ssize_t n = pread(out, marker, sizeof(marker) - 1, 0);
        if (n < 0) bad = 1;
        if (!killed && (bad || (births_seen == 3 && strstr(marker, "TRACER_DEATH_TREE_READY"))
            || monotonic() >= end - UINT64_C(1000000000))) {
            if (births_seen != 3 || !strstr(marker, "TRACER_DEATH_TREE_READY")) bad = 1;
            if (helper_fd < 0 || syscall(SYS_pidfd_send_signal, helper_fd, SIGKILL, NULL, 0)) bad = 1;
            killed = 1; close(command[1]);
        }
        for (;;) {
            int status; pid_t pid = waitpid(-1, &status, WNOHANG);
            if (!pid) break;
            if (pid < 0) { if (errno == EINTR) continue; if (errno == ECHILD) echild = 1; else bad = 1; break; }
            if (!WIFSIGNALED(status) || WTERMSIG(status) != SIGKILL || !killed) bad = 1;
            if (pid == helper) { if (helper_terminal) bad = 1; helper_terminal = 1; }
            else {
                unsigned i; for (i = 0; i < births_seen && owned[i].pid != pid; i++) {}
                if (i == births_seen || owned[i].terminal) bad = 1;
                else { owned[i].terminal = 1; terminals++; }
            }
        }
        if (killed && echild) break;
        struct pollfd p[2] = { { .fd = events[0], .events = POLLIN }, { .fd = channel[0], .events = POLLIN } };
        (void)poll(p, 2, 1);
    }
    if (!killed && helper_fd >= 0) { if (syscall(SYS_pidfd_send_signal, helper_fd, SIGKILL, NULL, 0)) bad = 1; }
    int logfd = open("witness.events", O_WRONLY | O_CREAT | O_EXCL | O_NOFOLLOW, 0600);
    if (logfd < 0 || write(logfd, log, used) != (ssize_t)used) bad = 1;
    if (logfd >= 0) close(logfd);
    for (unsigned i = 0; i < births_seen; i++) close(owned[i].fd);
    if (helper_fd >= 0) close(helper_fd);
    close(events[0]); close(channel[0]); close(out); close(err);
    if (bad || !echild || !helper_terminal || births_seen != 3 || terminals != births_seen) return 125;
    printf("WITNESS_CLOSED %u %u SIGKILL ECHILD\n", births_seen, terminals);
    return 0;
}
// These calls run only inside the owned helper's traced scope. An outer host
// EPERM is insufficient: the qualifier independently checks native counters.
static void uring_call(unsigned entries, void *parameters) {
    errno = 0; long result = syscall(SYS_io_uring_setup, entries, parameters); int error = errno;
    if (result >= 0) { close((int)result); _exit(99); } // Never submit ring operations.
    require(result == -1 && error == EPERM);
}
static void uring_one(void) {
    struct io_uring_params parameters = {0}, before = parameters;
    uring_call(1, &parameters); require(!memcmp(&parameters, &before, sizeof(parameters)));
}
static void *uring_thread(void *argument) { (void)argument; uring_one(); return NULL; }
static void uring_security(void) {
    long result; errno = 0; result = syscall(SYS_ptrace, 0, 0, NULL, NULL); require(result == -1 && errno == EPERM);
    errno = 0; result = syscall(SYS_seccomp, 0, 0, NULL); require(result == -1 && errno == EPERM);
    errno = 0; result = prctl(PR_SET_SECCOMP, 2, NULL); require(result == -1 && errno == EPERM);
    errno = 0; result = prctl(PR_SET_PTRACER, 0, 0, 0, 0); require(result == -1 && errno == EPERM);
    errno = 0; result = prctl(PR_SET_DUMPABLE, 0, 0, 0, 0); require(result == -1 && errno == EPERM);
    errno = 0; result = unshare(CLONE_NEWUSER); require(result == -1 && errno == EPERM);
    errno = 0; result = syscall(SYS_setns, -1, CLONE_NEWUSER); require(result == -1 && errno == EPERM);
    errno = 0; result = syscall(SYS_clone, CLONE_UNTRACED | SIGCHLD, NULL, NULL, NULL, 0);
    if (!result) { say("FORBIDDEN_URING_UNTRACED_CHILD\n"); _exit(97); }
    require(result == -1 && errno == EPERM);
}
static int uring_control(const char *mode) {
    if (!strcmp(mode, "uring-child")) { uring_one(); return 0; }
    if (!strcmp(mode, "uring-arguments")) {
        struct io_uring_params parameters = {0}, before = parameters;
        uring_call(0, NULL); uring_call(1, (void *)(uintptr_t)1);
        uring_call(UINT32_MAX, &parameters); require(!memcmp(&parameters, &before, sizeof(parameters)));
        parameters.flags = IORING_SETUP_SQPOLL; before = parameters;
        uring_call(1, &parameters); require(!memcmp(&parameters, &before, sizeof(parameters)));
    } else if (!strcmp(mode, "uring-threads")) {
        pthread_t threads[4];
        for (unsigned i = 0; i < 4; i++) require(!pthread_create(&threads[i], NULL, uring_thread, NULL));
        for (unsigned i = 0; i < 4; i++) require(!pthread_join(threads[i], NULL));
    } else if (!strcmp(mode, "uring-forkexec")) {
        pid_t child = fork(); require(child >= 0);
        if (!child) { char *const args[] = { (char *)self, "uring-child", NULL }; execv(self, args); _exit(99); }
        int status; require(waitpid(child, &status, 0) == child && WIFEXITED(status) && WEXITSTATUS(status) == 0);
    } else {
        unsigned calls = !strcmp(mode, "uring-repeat") ? 4 : 1;
        for (unsigned i = 0; i < calls; i++) uring_one();
        if (!strcmp(mode, "uring-mixed-security")) uring_security();
        if (!strcmp(mode, "uring-mixed-quota")) {
            init(); for (unsigned i = 1; i < 8; i++) add();
            errno = 0; pid_t child = fork();
            if (!child) { say("FORBIDDEN_URING_OVERFLOW_CHILD\n"); _exit(97); }
            require(child == -1 && errno == EAGAIN); release();
        }
    }
    printf("IO_URING_EPERM %s\n", mode);
    return !strcmp(mode, "uring-unhandled") ? 23 : 0;
}
int main(int argc, char **argv) {
    if (argc == 5 && !strcmp(argv[1], "witness")) return death_witness(argv[2], argv[3], argv[4]);
    require(argc == 2); self = argv[0]; setvbuf(stdout, NULL, _IONBF, 0);
    const char *m = argv[1];
    if (!strcmp(m, "uring-handled") || !strcmp(m, "uring-repeat") || !strcmp(m, "uring-arguments")
        || !strcmp(m, "uring-threads") || !strcmp(m, "uring-forkexec") || !strcmp(m, "uring-mixed-security")
        || !strcmp(m, "uring-mixed-quota") || !strcmp(m, "uring-unhandled") || !strcmp(m, "uring-child")) return uring_control(m);
    if (!strcmp(m, "limit8")) return quota(8, 0);
    if (!strcmp(m, "limit9")) return quota(9, 0);
    if (!strcmp(m, "overflow8")) return quota(8, 1);
    if (!strcmp(m, "overflow9")) return quota(9, 1);
    if (!strcmp(m, "threads")) return threads();
    if (!strcmp(m, "threadfork") || !strcmp(m, "threadexec")) {
        pthread_t t; require(!pthread_create(&t, NULL, !strcmp(m, "threadfork") ? thread_fork : thread_exec, NULL));
        if (!strcmp(m, "threadexec")) for (;;) pause_ms(100);
        void *value; require(!pthread_join(t, &value)); return (int)(long)value;
    }
    if (!strcmp(m, "concurrent")) return concurrent();
    if (!strcmp(m, "vfork")) return vfork_exec();
    if (!strcmp(m, "spawn")) return spawn_exec();
    if (!strcmp(m, "short")) return short_lived();
    if (!strcmp(m, "failedfork")) return failed_fork();
    if (!strcmp(m, "stdin0")) return stdin_control(0);
    if (!strcmp(m, "stdin65536")) return stdin_control(1);
    if (!strcmp(m, "rootfirst")) return orphan(1);
    if (!strcmp(m, "death-tree")) death_tree();
    if (!strcmp(m, "deadline") || !strcmp(m, "owner-eof") || !strcmp(m, "tracer-death")) return orphan(0);
    if (!strcmp(m, "exit23")) return 23;
    if (!strcmp(m, "signal")) { raise(SIGUSR1); return 99; }
    if (!strcmp(m, "exit0")) return 0;
    if (!strcmp(m, "sentinel")) { puts("FOREIGN_COORDINATOR_OWNED_SENTINEL"); for (;;) { pause_ms(100); say("SENTINEL_TICK\n"); } }
    return escape(m);
}
