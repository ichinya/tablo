// Linux x86-64 only. No JSON, cryptography, process discovery or external library.
// The pinned Node parent authenticates the plan and supplies private inherited FDs:
// 0 commands, 1 events, 3/4 captures, 5 finite input. Only the owned child is traced.
#define _GNU_SOURCE
#include <errno.h>
#include <fcntl.h>
#include <linux/audit.h>
#include <linux/filter.h>
#include <linux/seccomp.h>
#include <poll.h>
#include <sched.h>
#include <signal.h>
#include <stddef.h>
#include <stdint.h>
#include <stdio.h>
#include <stdlib.h>
#include <string.h>
#include <sys/prctl.h>
#include <sys/ptrace.h>
#include <linux/ptrace.h>
#include <sys/syscall.h>
#include <sys/stat.h>
#include <sys/socket.h>
#include <sys/uio.h>
#include <sys/types.h>
#include <sys/user.h>
#include <sys/wait.h>
#include <time.h>
#include <unistd.h>
#if !defined(__x86_64__) || defined(__ILP32__)
#error Only the reviewed native Linux x86-64 ABI is supported
#endif

#define POLICY "tgid-seccomp-ptrace-x64-v1"
#define TASKS 512
#define GROUPS 9
#define NS UINT64_C(1000000000)
enum { NONE, PROCESS, THREAD, REJECT, CLONE3 };
struct task { pid_t tid, tgid; int pending, born, reject_errno, newborn; };
struct group { pid_t tgid; int fd; };
static struct task tasks[TASKS];
static struct group groups[GROUPS];
static pid_t early_stops[TASKS];
static unsigned live, pending, peak, births, reservations, failed_creations;
static unsigned denied, clone3_fallbacks, waits, exec_retired, thread_births;
static unsigned command_count, input_bytes, written_bytes;
static int limit, failed, cancelled, ready, launched, started, root_terminal;
static int input_write = -1, input_settled, source_eof, root_code = -1, root_signal;
static int root_wait_status, closure_echild, shutting_down, owner_eof;
static pid_t root;
static int witness = -1;
static uint64_t end;
static volatile sig_atomic_t interrupted;

static uint64_t now(void) {
    struct timespec t;
    if (clock_gettime(CLOCK_MONOTONIC, &t)) _exit(125);
    return (uint64_t)t.tv_sec * NS + (uint64_t)t.tv_nsec;
}
static void interrupted_by(int sig) { (void)sig; interrupted = 1; }
static int event(const char *text) {
    size_t n = strlen(text), done = 0;
    while (done < n) {
        ssize_t r = write(1, text + done, n - done);
        if (r < 0 && errno == EINTR) continue;
        if (r <= 0) { failed = cancelled = 1; return -1; }
        done += (size_t)r;
    }
    return 0;
}
static struct task *task(pid_t tid) {
    for (unsigned i = 0; i < TASKS; i++) if (tasks[i].tid == tid) return &tasks[i];
    return NULL;
}
static struct group *group(pid_t tgid) {
    for (unsigned i = 0; i < GROUPS; i++) if (groups[i].tgid == tgid) return &groups[i];
    return NULL;
}
static int witness_birth(pid_t tgid, int fd) {
    if (witness < 0) return 0;
    int record[2] = { 1, tgid };
    union { struct cmsghdr align; unsigned char bytes[CMSG_SPACE(sizeof(int))]; } control;
    memset(&control, 0, sizeof(control));
    struct iovec io = { .iov_base = record, .iov_len = sizeof(record) };
    struct msghdr message = { .msg_iov = &io, .msg_iovlen = 1, .msg_control = control.bytes, .msg_controllen = sizeof(control.bytes) };
    struct cmsghdr *c = CMSG_FIRSTHDR(&message);
    c->cmsg_level = SOL_SOCKET; c->cmsg_type = SCM_RIGHTS; c->cmsg_len = CMSG_LEN(sizeof(int));
    memcpy(CMSG_DATA(c), &fd, sizeof(fd));
    return sendmsg(witness, &message, MSG_DONTWAIT | MSG_NOSIGNAL) == (ssize_t)sizeof(record) ? 0 : -1;
}
static struct task *add_task(pid_t tid, pid_t tgid, int newborn) {
    if (task(tid)) return NULL;
    for (unsigned i = 0; i < TASKS; i++) if (!tasks[i].tid) {
        tasks[i] = (struct task){ .tid = tid, .tgid = tgid, .newborn = newborn };
        return &tasks[i];
    }
    return NULL;
}
static int add_group(pid_t tgid) {
    if (group(tgid) || live >= (unsigned)limit) return -1;
    int fd = (int)syscall(SYS_pidfd_open, tgid, 0);
    if (fd < 0) return -1;
    for (unsigned i = 0; i < GROUPS; i++) if (!groups[i].tgid) {
        groups[i] = (struct group){ .tgid = tgid, .fd = fd };
        live++; births++; if (live + pending > peak) peak = live + pending;
        return witness_birth(tgid, fd);
    }
    close(fd); return -1;
}
static void cancel(void) {
    cancelled = 1;
    for (unsigned i = 0; i < GROUPS; i++) if (groups[i].tgid) {
        if (syscall(SYS_pidfd_send_signal, groups[i].fd, SIGKILL, NULL, 0) && errno != ESRCH) failed = 1;
    }
}
static int resume(struct task *t, int signal) {
    enum __ptrace_request request = t->pending ? PTRACE_SYSCALL : PTRACE_CONT;
    if (ptrace(request, t->tid, NULL, (void *)(intptr_t)signal)) return -1;
    return 0;
}
static int suppress(struct task *t, struct user_regs_struct *regs, int error, int kind) {
    regs->orig_rax = (unsigned long long)-1;
    if (ptrace(PTRACE_SETREGS, t->tid, NULL, regs)) return -1;
    t->pending = kind; t->reject_errno = error;
    return resume(t, 0);
}
static int install_filter(void) {
#define TRACE(n) BPF_JUMP(BPF_JMP | BPF_JEQ | BPF_K, (n), 0, 1), BPF_STMT(BPF_RET | BPF_K, SECCOMP_RET_TRACE)
    struct sock_filter code[] = {
        BPF_STMT(BPF_LD | BPF_W | BPF_ABS, offsetof(struct seccomp_data, arch)),
        BPF_JUMP(BPF_JMP | BPF_JEQ | BPF_K, AUDIT_ARCH_X86_64, 1, 0),
        BPF_STMT(BPF_RET | BPF_K, SECCOMP_RET_KILL_PROCESS),
        BPF_STMT(BPF_LD | BPF_W | BPF_ABS, offsetof(struct seccomp_data, nr)),
        BPF_JUMP(BPF_JMP | BPF_JGE | BPF_K, 0x40000000U, 0, 1),
        BPF_STMT(BPF_RET | BPF_K, SECCOMP_RET_KILL_PROCESS),
        TRACE(SYS_fork), TRACE(SYS_vfork), TRACE(SYS_clone), TRACE(SYS_clone3),
        TRACE(SYS_execve), TRACE(SYS_execveat), TRACE(SYS_ptrace), TRACE(SYS_seccomp),
        TRACE(SYS_prctl), TRACE(SYS_unshare), TRACE(SYS_setns), TRACE(SYS_io_uring_setup),
        BPF_STMT(BPF_RET | BPF_K, SECCOMP_RET_ALLOW)
    };
#undef TRACE
    struct sock_fprog program = { .len = (unsigned short)(sizeof(code) / sizeof(code[0])), .filter = code };
    return prctl(PR_SET_NO_NEW_PRIVS, 1, 0, 0, 0) || prctl(PR_SET_SECCOMP, SECCOMP_MODE_FILTER, &program);
}
static int gate(struct task *t) {
    struct ptrace_syscall_info info;
    struct user_regs_struct regs;
    long size = ptrace(PTRACE_GET_SYSCALL_INFO, t->tid, sizeof(info), &info);
    if (size < (long)(offsetof(struct ptrace_syscall_info, seccomp.ret_data) + sizeof(info.seccomp.ret_data))
        || info.op != PTRACE_SYSCALL_INFO_SECCOMP || info.arch != AUDIT_ARCH_X86_64
        || ptrace(PTRACE_GETREGS, t->tid, NULL, &regs) || info.seccomp.nr != regs.orig_rax || t->pending) return -1;
    uint64_t nr = info.seccomp.nr, flags = regs.rdi;
    if (nr == SYS_execve || nr == SYS_execveat) {
        if (!ready) {
            char line[128]; ready = 1;
            snprintf(line, sizeof(line), "READY %ld %s %llu\n", (long)getpid(), POLICY, (unsigned long long)now());
            return event(line); // Remain stopped before the initial controller exec.
        }
        return resume(t, 0);
    }
    if (!launched) return -1;
    if (nr == SYS_clone3) { clone3_fallbacks++; return suppress(t, &regs, ENOSYS, CLONE3); }
    if (nr == SYS_prctl && flags != PR_SET_SECCOMP && flags != PR_SET_PTRACER
        && flags != PR_SET_DUMPABLE) return resume(t, 0);
    if (nr != SYS_clone && nr != SYS_fork && nr != SYS_vfork) {
        denied++; failed = 1; return suppress(t, &regs, EPERM, REJECT);
    }
    const uint64_t allowed = CSIGNAL | CLONE_VM | CLONE_FS | CLONE_FILES | CLONE_SIGHAND
        | CLONE_THREAD | CLONE_SYSVSEM | CLONE_SETTLS | CLONE_PARENT_SETTID
        | CLONE_CHILD_CLEARTID | CLONE_CHILD_SETTID | CLONE_VFORK | CLONE_DETACHED | CLONE_PIDFD;
    if (nr == SYS_clone && ((flags & ~allowed) || (flags & CSIGNAL) > 64)) {
        denied++; failed = 1; return suppress(t, &regs, EPERM, REJECT);
    }
    int thread = nr == SYS_clone && (flags & CLONE_THREAD);
    if (!thread) {
        if (live + pending >= (unsigned)limit) {
            denied++; failed = 1; return suppress(t, &regs, EAGAIN, REJECT);
        }
        pending++; reservations++; if (live + pending > peak) peak = live + pending;
    }
    t->pending = thread ? THREAD : PROCESS; t->born = 0;
    return resume(t, 0);
}
static int syscall_exit(struct task *t) {
    struct ptrace_syscall_info info;
    struct user_regs_struct regs;
    long size = ptrace(PTRACE_GET_SYSCALL_INFO, t->tid, sizeof(info), &info);
    if (!t->pending || size < (long)(offsetof(struct ptrace_syscall_info, exit.is_error) + sizeof(info.exit.is_error))
        || info.op != PTRACE_SYSCALL_INFO_EXIT || info.arch != AUDIT_ARCH_X86_64) return -1;
    if (t->pending == REJECT || t->pending == CLONE3) {
        if (ptrace(PTRACE_GETREGS, t->tid, NULL, &regs)) return -1;
        regs.rax = (unsigned long long)-(long long)t->reject_errno;
        if (ptrace(PTRACE_SETREGS, t->tid, NULL, &regs)) return -1;
    } else if (info.exit.is_error) {
        if (t->born) return -1;
        if (t->pending == PROCESS) { if (!pending) return -1; pending--; }
        failed_creations++;
    } else if (!t->born) return -1;
    t->pending = NONE; t->born = 0;
    return resume(t, 0);
}
static int birth(struct task *parent) {
    unsigned long child;
    if ((parent->pending != PROCESS && parent->pending != THREAD) || parent->born
        || ptrace(PTRACE_GETEVENTMSG, parent->tid, NULL, &child) || !child || child > INT32_MAX) return -1;
    int thread = parent->pending == THREAD;
    if (!add_task((pid_t)child, thread ? parent->tgid : (pid_t)child, 1)) return -1;
    if (thread) thread_births++;
    else {
        if (!pending) return -1;
        pending--; // Atomically exchange this reservation for the held newborn TGID.
        if (add_group((pid_t)child)) return -1;
    }
    parent->born = 1;
    for (unsigned i = 0; i < TASKS; i++) if (early_stops[i] == (pid_t)child) {
        early_stops[i] = 0;
        struct task *t = task((pid_t)child); t->newborn = 0;
        if (resume(t, 0)) return -1;
    }
    if (cancelled) cancel();
    return resume(parent, 0);
}
static int exec_event(pid_t tid) {
    unsigned long old;
    if (ptrace(PTRACE_GETEVENTMSG, tid, NULL, &old) || old > INT32_MAX) return -1;
    struct task *t = task((pid_t)old);
    if (!t || t->pending || !group(t->tgid) || t->tgid != tid) return -1;
    // The kernel's exec event replaces the former leader identity. Keep its TGID
    // and pidfd; never decrement the process count for this identity exchange.
    struct task *leader = task(tid);
    if (leader && leader != t) {
        if (leader->pending == PROCESS && !leader->born) {
            if (!pending) return -1;
            pending--; failed_creations++;
        }
        memset(leader, 0, sizeof(*leader)); exec_retired++;
    }
    t->tid = tid;
    if (!started) {
        if (tid != root || !launched) return -1;
        started = 1;
        char line[64]; snprintf(line, sizeof(line), "STARTED %ld\n", (long)root); event(line);
    }
    return resume(t, 0);
}
static int terminal(struct task *t, int status) {
    if (t->pending == PROCESS && !t->born) { if (!pending) return -1; pending--; failed_creations++; }
    pid_t tid = t->tid, tgid = t->tgid;
    if (WIFSIGNALED(status) && WTERMSIG(status) == SIGSYS) failed = 1;
    memset(t, 0, sizeof(*t)); waits++;
    for (unsigned i = 0; i < TASKS; i++) if (tasks[i].tgid == tgid) return 0;
    struct group *g = group(tgid);
    if (!g || !live) return -1;
    close(g->fd); memset(g, 0, sizeof(*g)); live--;
    if (tgid == root) {
        // A leader's status can arrive before the group's final thread status.
        if (tid == root) root_wait_status = status;
        root_terminal = 1; root_code = WIFEXITED(root_wait_status) ? WEXITSTATUS(root_wait_status) : -1;
        root_signal = WIFSIGNALED(root_wait_status) ? WTERMSIG(root_wait_status) : 0;
        char line[128]; snprintf(line, sizeof(line), "EXITED %ld %d %d %d\n", (long)root, root_code, root_signal, cancelled); event(line);
        if (live || pending) { failed = 1; cancel(); }
    }
    return 0;
}
static int reap(void) {
    int status;
    pid_t tid;
    for (unsigned iteration = 0; iteration < TASKS; iteration++) {
        tid = waitpid(-1, &status, __WALL | WNOHANG);
        if (tid < 0) {
            if (errno == EINTR) continue;
            if (errno == ECHILD && !live && !pending) {
                for (unsigned i = 0; i < TASKS; i++) if (tasks[i].tid || early_stops[i]) return -1;
                closure_echild = 1; return 0;
            }
            return -1;
        }
        if (!tid) return 0;
        struct task *t = task(tid);
        if (WIFEXITED(status) || WIFSIGNALED(status)) {
            if (!t) return -1;
            if (tid == root) root_wait_status = status;
            if (terminal(t, status)) return -1;
            continue;
        }
        if (!WIFSTOPPED(status)) return -1;
        unsigned ev = (unsigned)status >> 16;
        int sig = WSTOPSIG(status);
        if (ev == PTRACE_EVENT_EXEC) { if (exec_event(tid)) return -1; }
        else if (!t) {
            // Birth notification and the child's automatic SIGSTOP are separate
            // kernel waits. Hold an early stop until a known parent's event proves
            // its ownership. It cannot run, and an unmatched stop forbids closure.
            if (ev || sig != SIGSTOP) return -1;
            unsigned i;
            for (i = 0; i < TASKS; i++) if (early_stops[i] == tid) return -1;
            for (i = 0; i < TASKS && early_stops[i]; i++) {}
            if (i == TASKS) return -1;
            early_stops[i] = tid;
        }
        else if (ev == PTRACE_EVENT_SECCOMP) { if (gate(t)) return -1; }
        else if (ev == PTRACE_EVENT_FORK || ev == PTRACE_EVENT_VFORK || ev == PTRACE_EVENT_CLONE) { if (birth(t)) return -1; }
        else if (ev == PTRACE_EVENT_EXIT) { if (resume(t, 0)) return -1; }
        else if (ev) return -1;
        else if (t->newborn && sig == SIGSTOP) { t->newborn = 0; if (resume(t, 0)) return -1; }
        else if (sig == (SIGTRAP | 0x80)) { if (syscall_exit(t)) return -1; }
        else {
            siginfo_t si;
            if (ptrace(PTRACE_GETSIGINFO, tid, NULL, &si)) return -1; // No silent group-stop escape.
            if (resume(t, sig)) return -1;
        }
    }
    return 0;
}
static void input(void) {
    static unsigned char buffer[16384];
    static size_t used, sent;
    if (input_settled || !started) return;
    if (!cancelled && sent == used && written_bytes < input_bytes) {
        size_t wanted = input_bytes - written_bytes;
        if (wanted > sizeof(buffer)) wanted = sizeof(buffer);
        ssize_t n = read(5, buffer, wanted);
        if (n < 0 && (errno == EINTR || errno == EAGAIN)) return;
        if (n <= 0) { failed = 1; cancel(); }
        else { used = (size_t)n; sent = 0; }
    }
    if (!cancelled && sent < used) {
        ssize_t n = write(input_write, buffer + sent, used - sent);
        if (n > 0) { sent += (size_t)n; written_bytes += (unsigned)n; }
        else if (n < 0 && errno != EAGAIN && errno != EINTR) { failed = 1; cancel(); }
    }
    if (!cancelled && written_bytes == input_bytes && !source_eof) {
        unsigned char extra; ssize_t n = read(5, &extra, 1);
        if (n < 0 && (errno == EINTR || errno == EAGAIN)) return;
        if (n == 0) source_eof = 1;
        else { failed = 1; cancel(); }
    }
    if (cancelled || (written_bytes == input_bytes && source_eof)) {
        memset(buffer, 0, sizeof(buffer)); close(input_write); input_write = -1;
        close(5); input_settled = 1;
        char line[96]; snprintf(line, sizeof(line), "INPUT %u %u %d %d\n", input_bytes, written_bytes, cancelled, source_eof); event(line);
    }
}
static int commands(void) {
    static char buffer[1024]; static size_t used;
    ssize_t n = read(0, buffer + used, sizeof(buffer) - used);
    if (n < 0 && (errno == EAGAIN || errno == EINTR)) return 0;
    if (n <= 0) { owner_eof = 1; if (!shutting_down) { failed = 1; cancel(); } return 0; }
    used += (size_t)n;
    char *line;
    while ((line = memchr(buffer, '\n', used))) {
        size_t bytes = (size_t)(line - buffer) + 1;
        if (++command_count > 64) return -1;
        if (bytes == 7 && !memcmp(buffer, "LAUNCH\n", 7) && ready && !launched && !cancelled) {
            launched = 1; struct task *t = task(root); if (!t || resume(t, 0)) return -1;
        } else if (bytes == 7 && !memcmp(buffer, "CANCEL\n", 7)) { failed = 1; cancel(); }
        else if (bytes == 9 && !memcmp(buffer, "SHUTDOWN\n", 9) && closure_echild) shutting_down = 1;
        else return -1;
        used -= bytes; memmove(buffer, buffer + bytes, used);
    }
    return used == sizeof(buffer) ? -1 : 0;
}
static int number(const char *s, uint64_t *n) {
    if (!*s) return -1;
    *n = 0;
    for (; *s; s++) { if (*s < '0' || *s > '9' || *n > (UINT64_MAX - 9) / 10) return -1; *n = *n * 10 + (unsigned)(*s - '0'); }
    return 0;
}
int main(int argc, char **argv) {
    uint64_t cap, bytes;
    if (argc < 6 || number(argv[1], &cap) || (cap != 8 && cap != 9)
        || number(argv[2], &end) || number(argv[3], &bytes) || bytes > 1048576
        || (strcmp(argv[4], "--") && strcmp(argv[4], "--witness=6")) || argv[5][0] != '/' || end <= now() + NS || end - now() > 1800 * NS) return 125;
    limit = (int)cap; input_bytes = (unsigned)bytes;
    signal(SIGPIPE, SIG_IGN); signal(SIGINT, interrupted_by); signal(SIGTERM, interrupted_by);
    if (prctl(PR_SET_CHILD_SUBREAPER, 1, 0, 0, 0)) return 125;
    if (!strcmp(argv[4], "--witness=6")) {
        int type; socklen_t length = sizeof(type); struct ucred peer; socklen_t peer_length = sizeof(peer);
        witness = 6;
        if (getsockopt(witness, SOL_SOCKET, SO_TYPE, &type, &length) || type != SOCK_SEQPACKET
            || getsockopt(witness, SOL_SOCKET, SO_PEERCRED, &peer, &peer_length)
            || peer.pid != getppid() || peer.uid != geteuid() || fcntl(witness, F_SETFD, FD_CLOEXEC)) return 125;
    }
    struct stat capture_out, capture_err, source;
    if (fstat(3, &capture_out) || !S_ISREG(capture_out.st_mode)
        || fstat(4, &capture_err) || !S_ISREG(capture_err.st_mode)
        || fstat(5, &source) || (!S_ISREG(source.st_mode) && !S_ISFIFO(source.st_mode) && !S_ISSOCK(source.st_mode))) return 125;
    for (int fd = 0; fd <= 5; fd++) if (fd != 2 && fcntl(fd, F_SETFD, FD_CLOEXEC)) return 125;
    if (fcntl(0, F_SETFL, O_NONBLOCK) || fcntl(5, F_SETFL, fcntl(5, F_GETFL) | O_NONBLOCK)) return 125;
    int pipefd[2]; if (pipe2(pipefd, O_CLOEXEC)) return 125;
    pid_t tracer = getpid(); root = fork();
    if (root < 0) return 125;
    if (!root) {
        signal(SIGINT, SIG_DFL); signal(SIGTERM, SIG_DFL); signal(SIGPIPE, SIG_DFL);
        close(pipefd[1]);
        if (prctl(PR_SET_PDEATHSIG, SIGKILL) || getppid() != tracer
            || dup2(pipefd[0], 0) < 0 || dup2(3, 1) < 0 || dup2(4, 2) < 0
            || syscall(SYS_close_range, 3U, ~0U, 0U)
            || ptrace(PTRACE_TRACEME, 0, NULL, NULL) || raise(SIGSTOP)) _exit(125);
        if (install_filter()) _exit(125);
        execv(argv[5], &argv[5]); _exit(125);
    }
    close(pipefd[0]); input_write = pipefd[1];
    if (add_group(root) || !add_task(root, root, 0)) return 125;
    int status; pid_t stopped;
    for (;;) {
        if (interrupted || now() >= end - NS || commands()) { failed = 1; cancel(); }
        stopped = waitpid(root, &status, WNOHANG);
        if (stopped > 0 || (stopped < 0 && errno != EINTR)) break;
        if (now() >= end) { cancel(); return 125; }
        struct pollfd p = { .fd = 0, .events = POLLIN }; (void)poll(&p, 1, 1);
    }
    if (stopped != root || !WIFSTOPPED(status) || WSTOPSIG(status) != SIGSTOP) return 125;
    unsigned long options = PTRACE_O_TRACEFORK | PTRACE_O_TRACEVFORK | PTRACE_O_TRACECLONE
        | PTRACE_O_TRACEEXEC | PTRACE_O_TRACEEXIT | PTRACE_O_TRACESECCOMP | PTRACE_O_EXITKILL | PTRACE_O_TRACESYSGOOD;
    if (cancelled || ptrace(PTRACE_SETOPTIONS, root, NULL, (void *)options) || fcntl(input_write, F_SETFL, O_NONBLOCK)
        || resume(task(root), 0)) return 125; // EXITKILL/PDEATHSIG remain the fail-closed backstop.
    int emitted = 0;
    while (now() < end) {
        if (interrupted || now() >= end - NS) { failed = 1; cancel(); }
        if (commands() || reap()) { failed = 1; cancel(); }
        input();
        if (closure_echild && !emitted) {
            emitted = 1;
            if (!root_terminal || !started || !input_settled || pending || fsync(3) || fsync(4)) failed = 1;
            close(3); close(4);
            char line[512];
            snprintf(line, sizeof(line), "CLOSED %u %u %u %u %u %u %u %u %u %u %u %d %d\n",
                live, pending, peak, births, reservations, failed_creations, denied, clone3_fallbacks,
                thread_births, waits, exec_retired, closure_echild, failed || cancelled);
            event(line);
        }
        if (emitted && (shutting_down || owner_eof)) return failed || cancelled || !root_terminal ? 125 : 0;
        struct pollfd p = { .fd = 0, .events = POLLIN }; (void)poll(&p, 1, 1);
    }
    cancel(); // Deadline does not gain a fresh settlement window.
    return 125;
}
