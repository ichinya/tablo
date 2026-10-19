// Pure fault shim for the actual native transitions. Separately authorized
// isolated compilation/execution only; never part of the production recipe.
#define _GNU_SOURCE
#include <assert.h>
#include <errno.h>
#include <fcntl.h>
#include <linux/audit.h>
#include <linux/filter.h>
#include <linux/seccomp.h>
#include <poll.h>
#include <sched.h>
#include <signal.h>
#include <stdarg.h>
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

static _Noreturn void unit_forbidden(const char *name) { fprintf(stderr, "UNEXPECTED_UNIT_EFFECT %s\n", name); exit(120); }
static struct ptrace_syscall_info unit_info;
static struct user_regs_struct unit_regs;
static long unit_info_size;
static unsigned unit_sets, unit_resumes, unit_set_failure, unit_resume_failure, unit_kills, unit_waits;
static int unit_get_failure, unit_event_old, unit_wait_status, unit_wait_tid;
static char unit_events[1024];
static long unit_ptrace(enum __ptrace_request request, ...) {
    va_list arguments; va_start(arguments, request);
    pid_t tid = va_arg(arguments, pid_t); assert(tid > 0);
    if (request == PTRACE_GET_SYSCALL_INFO) (void)va_arg(arguments, size_t);
    else (void)va_arg(arguments, void *);
    void *data = va_arg(arguments, void *); va_end(arguments);
    if (request == PTRACE_GET_SYSCALL_INFO) { memcpy(data, &unit_info, sizeof(unit_info)); return unit_info_size; }
    if (request == PTRACE_GETREGS) { if (unit_get_failure) return -1; memcpy(data, &unit_regs, sizeof(unit_regs)); return 0; }
    if (request == PTRACE_SETREGS) {
        if (++unit_sets == unit_set_failure) return -1;
        unit_regs = *(struct user_regs_struct *)data; return 0;
    }
    if (request == PTRACE_SYSCALL || request == PTRACE_CONT) {
        assert(unit_regs.orig_rax == (unsigned long long)-1);
        if (++unit_resumes == unit_resume_failure) return -1;
        return 0;
    }
    if (request == PTRACE_GETEVENTMSG) { *(unsigned long *)data = (unsigned long)unit_event_old; return 0; }
    unit_forbidden("ptrace");
}
static long unit_syscall(long number, ...) {
    va_list arguments; va_start(arguments, number);
    if (number == SYS_pidfd_send_signal) {
        int fd = va_arg(arguments, int), sig = va_arg(arguments, int);
        assert(fd == 9 && sig == SIGKILL); unit_kills++; va_end(arguments); return 0;
    }
    va_end(arguments); unit_forbidden("syscall");
}
static ssize_t unit_write(int fd, const void *bytes, size_t length) {
    assert(fd == 1 && strlen(unit_events) + length < sizeof(unit_events));
    memcpy(unit_events + strlen(unit_events), bytes, length); return (ssize_t)length;
}
static ssize_t unit_read(int fd, void *bytes, size_t length) { (void)fd; (void)bytes; (void)length; unit_forbidden("read"); }
static int unit_close(int fd) { assert(fd == 9); return 0; }
static int unit_fsync(int fd) { (void)fd; unit_forbidden("fsync"); }
static int unit_clock_gettime(clockid_t clock, struct timespec *value) { assert(clock == CLOCK_MONOTONIC); *value = (struct timespec){ .tv_sec = 100 }; return 0; }
static pid_t unit_getpid(void) { return 100; }
static pid_t unit_getppid(void) { unit_forbidden("getppid"); }
static uid_t unit_geteuid(void) { unit_forbidden("geteuid"); }
static ssize_t unit_sendmsg(int fd, const struct msghdr *message, int flags) { (void)fd; (void)message; (void)flags; unit_forbidden("sendmsg"); }
static pid_t unit_waitpid(pid_t pid, int *status, int options) {
    assert(pid == -1 && options == (__WALL | WNOHANG));
    if (unit_waits++ == 0) { *status = unit_wait_status; return unit_wait_tid; }
    return 0;
}
static int unit_poll(struct pollfd *fds, nfds_t count, int timeout) { (void)fds; (void)count; (void)timeout; unit_forbidden("poll"); }
static int unit_prctl(int option, ...) { (void)option; unit_forbidden("prctl"); }
static int unit_getsockopt(int fd, int level, int option, void *value, socklen_t *length) { (void)fd; (void)level; (void)option; (void)value; (void)length; unit_forbidden("getsockopt"); }
static int unit_fcntl(int fd, int command, ...) { (void)fd; (void)command; unit_forbidden("fcntl"); }
static int unit_fstat(int fd, struct stat *value) { (void)fd; (void)value; unit_forbidden("fstat"); }
static int unit_pipe2(int fds[2], int flags) { (void)fds; (void)flags; unit_forbidden("pipe2"); }
static pid_t unit_fork(void) { unit_forbidden("fork"); }
static void (*unit_signal(int signal_number, void (*handler)(int)))(int) { (void)signal_number; (void)handler; unit_forbidden("signal"); }
static int unit_dup2(int oldfd, int newfd) { (void)oldfd; (void)newfd; unit_forbidden("dup2"); }
static int unit_raise(int signal_number) { (void)signal_number; unit_forbidden("raise"); }
static int unit_execv(const char *file, char *const arguments[]) { (void)file; (void)arguments; unit_forbidden("execv"); }
static _Noreturn void unit_exit(int code) { (void)code; unit_forbidden("_exit"); }

#define ptrace unit_ptrace
#define syscall unit_syscall
#define write unit_write
#define read unit_read
#define close unit_close
#define fsync unit_fsync
#define clock_gettime unit_clock_gettime
#define getpid unit_getpid
#define getppid unit_getppid
#define geteuid unit_geteuid
#define sendmsg unit_sendmsg
#define waitpid unit_waitpid
#define poll unit_poll
#define prctl unit_prctl
#define getsockopt unit_getsockopt
#define fcntl unit_fcntl
#define fstat unit_fstat
#define pipe2 unit_pipe2
#define fork unit_fork
#define signal unit_signal
#define dup2 unit_dup2
#define raise unit_raise
#define execv unit_execv
#define _exit unit_exit
#define main included_native_main
#include "server-supervisor-linux.c"
#undef main

static unsigned unit_cases;
static struct task *unit_begin(void) {
    unit_cases++;
    memset(tasks, 0, sizeof(tasks)); memset(groups, 0, sizeof(groups)); memset(early_stops, 0, sizeof(early_stops));
    memset(denial_buckets, 0, sizeof(denial_buckets)); memset(&unit_info, 0, sizeof(unit_info)); memset(&unit_regs, 0, sizeof(unit_regs));
    memset(unit_events, 0, sizeof(unit_events));
    live = births = peak = 1; pending = reservations = failed_creations = denied = clone3_fallbacks = waits = exec_retired = thread_births = 0;
    io_uring_setup_attempts = io_uring_setup_eperm_writes = eperm_register_writes = eagain_register_writes = 0;
    failed = cancelled = root_terminal = root_code = root_signal = root_wait_status = closure_echild = 0;
    ready = launched = started = 1; limit = 8; root = 101; groups[0] = (struct group){ .tgid = root, .fd = 9 };
    unit_sets = unit_resumes = unit_set_failure = unit_resume_failure = unit_kills = unit_waits = 0;
    unit_get_failure = unit_event_old = unit_wait_status = unit_wait_tid = 0;
    unit_info.op = PTRACE_SYSCALL_INFO_SECCOMP; unit_info.arch = AUDIT_ARCH_X86_64; unit_info.seccomp.nr = SYS_io_uring_setup;
    unit_regs.orig_rax = SYS_io_uring_setup; unit_regs.rdi = 1; unit_regs.rsi = 1; unit_info_size = sizeof(unit_info);
    return add_task(root, root, 0);
}
static void unit_complete(struct task *t) {
    unit_info.op = PTRACE_SYSCALL_INFO_EXIT; unit_info.exit.is_error = 1;
    assert(syscall_exit(t) == 0 && t->pending == NONE);
    assert(unit_regs.rax == (unsigned long long)-(long long)EPERM);
}
int main(void) {
    struct task *t = unit_begin();
    assert(gate(t) == 0 && !failed && denied == 1 && io_uring_setup_attempts == 1 && !io_uring_setup_eperm_writes);
    assert(t->pending == URING_EPERM && unit_sets == 1 && unit_resumes == 1);
    unit_complete(t); assert(io_uring_setup_eperm_writes == 1 && eperm_register_writes == 1 && !failed);
    fallback_closure(); assert(!failed);

    t = unit_begin(); t->tid = 102; // Owned thread, same TGID and no reservation.
    assert(gate(t) == 0); unit_complete(t);
    assert(io_uring_setup_attempts == 1 && io_uring_setup_eperm_writes == 1 && births == 1 && !reservations);

    t = unit_begin();
    for (unsigned i = 0; i < 4; i++) {
        unit_info.op = PTRACE_SYSCALL_INFO_SECCOMP; unit_info.seccomp.nr = SYS_io_uring_setup; unit_regs.orig_rax = SYS_io_uring_setup;
        unit_regs.rdi = i ? UINT32_MAX : 0; unit_regs.rsi = i & 1 ? 1 : 0;
        assert(gate(t) == 0); unit_complete(t);
    }
    assert(denied == 4 && io_uring_setup_attempts == 4 && io_uring_setup_eperm_writes == 4 && !reservations && births == 1);

    for (unsigned fault = 0; fault < 8; fault++) {
        t = unit_begin();
        if (fault == 0) unit_info_size = 0;
        if (fault == 1) unit_info.op = PTRACE_SYSCALL_INFO_ENTRY;
        if (fault == 2) unit_info.arch = AUDIT_ARCH_I386;
        if (fault == 3) unit_info.seccomp.nr = SYS_fork;
        if (fault == 4) t->pending = THREAD;
        if (fault == 5) unit_get_failure = 1;
        if (fault == 6) unit_set_failure = 1;
        if (fault == 7) unit_resume_failure = 1;
        assert(gate(t) == -1); assert(io_uring_setup_eperm_writes == 0);
        if (fault < 6) assert(denied == 0 && unit_sets == 0 && unit_resumes == 0);
        if (fault == 6) assert(unit_resumes == 0);
        fallback_closure(); if (fault >= 6) assert(failed);
    }
    for (unsigned fault = 0; fault < 9; fault++) {
        t = unit_begin(); assert(gate(t) == 0); unit_info.op = PTRACE_SYSCALL_INFO_EXIT;
        if (fault == 0) unit_info_size = 0;
        if (fault == 1) unit_info.op = PTRACE_SYSCALL_INFO_ENTRY;
        if (fault == 2) unit_info.arch = AUDIT_ARCH_I386;
        if (fault == 3) t->pending = NONE;
        if (fault == 4) t->reject_errno = EAGAIN;
        if (fault == 5) unit_get_failure = 1;
        if (fault == 6) unit_set_failure = 2;
        if (fault == 7) unit_regs.orig_rax = SYS_io_uring_setup;
        if (fault == 8) unit_resume_failure = 2;
        assert(syscall_exit(t) == -1);
        assert(io_uring_setup_eperm_writes == (fault == 8 ? 1U : 0U));
        fallback_closure(); if (fault != 8) assert(failed);
    }
    t = unit_begin(); assert(gate(t) == 0); assert(terminal(t, 0) == 0 && failed && io_uring_setup_eperm_writes == 0);
    t = unit_begin(); assert(gate(t) == 0);
    struct task *thread = add_task(102, root, 0); assert(thread); unit_event_old = 102;
    assert(exec_event(root) == -1 && task(root)->pending == URING_EPERM);

    t = unit_begin(); failed = 1; assert(gate(t) == 0); unit_complete(t); fallback_closure(); assert(failed);
    t = unit_begin(); cancelled = 1; assert(gate(t) == 0); unit_complete(t); fallback_closure(); assert(cancelled);
    t = unit_begin(); denied = io_uring_setup_attempts = io_uring_setup_eperm_writes = INT32_MAX - 1U;
    assert(gate(t) == 0); unit_complete(t); assert(denied == INT32_MAX && io_uring_setup_eperm_writes == INT32_MAX);
    unit_info.op = PTRACE_SYSCALL_INFO_SECCOMP; unit_info.seccomp.nr = unit_regs.orig_rax = SYS_io_uring_setup;
    assert(gate(t) == -1 && failed && denied == INT32_MAX && unit_sets == 2);
    t = unit_begin(); denied = INT32_MAX; assert(gate(t) == -1 && failed && io_uring_setup_attempts == 0 && unit_sets == 0);
    t = unit_begin(); io_uring_setup_attempts = INT32_MAX; assert(gate(t) == -1 && failed && denied == 0 && unit_sets == 0);
    t = unit_begin(); assert(gate(t) == 0); io_uring_setup_eperm_writes = INT32_MAX; unit_info.op = PTRACE_SYSCALL_INFO_EXIT;
    assert(syscall_exit(t) == -1 && failed && unit_sets == 1 && io_uring_setup_eperm_writes == INT32_MAX);
    t = unit_begin(); io_uring_setup_attempts = 1; fallback_closure(); assert(failed);
    t = unit_begin(); io_uring_setup_attempts = io_uring_setup_eperm_writes = 1; fallback_closure(); assert(failed);

    t = unit_begin(); unit_info.seccomp.nr = unit_regs.orig_rax = SYS_ptrace;
    assert(gate(t) == 0 && failed && denied == 1 && t->pending == REJECT && io_uring_setup_attempts == 0);
    unit_info.op = PTRACE_SYSCALL_INFO_EXIT; assert(syscall_exit(t) == 0 && eperm_register_writes == 1 && !io_uring_setup_eperm_writes);
    t = unit_begin(); denied = INT32_MAX; unit_info.seccomp.nr = unit_regs.orig_rax = SYS_ptrace;
    assert(gate(t) == -1 && failed && denied == INT32_MAX && !unit_sets && !unit_resumes);
    t = unit_begin(); live = 8; unit_info.seccomp.nr = unit_regs.orig_rax = SYS_fork;
    assert(gate(t) == 0 && failed && denied == 1 && t->reject_errno == EAGAIN);
    unit_info.op = PTRACE_SYSCALL_INFO_EXIT; assert(syscall_exit(t) == 0 && eagain_register_writes == 1 && !io_uring_setup_attempts);
    t = unit_begin(); unit_info.seccomp.nr = unit_regs.orig_rax = SYS_clone3;
    assert(gate(t) == 0 && !failed && t->pending == CLONE3 && !denied && clone3_fallbacks == 1);
    unit_info.op = PTRACE_SYSCALL_INFO_EXIT; assert(syscall_exit(t) == 0 && unit_regs.rax == (unsigned long long)-(long long)ENOSYS);

    t = unit_begin(); assert(gate(t) == 0); unit_info.op = PTRACE_SYSCALL_INFO_EXIT; unit_set_failure = 2;
    unit_wait_tid = root; unit_wait_status = ((SIGTRAP | 0x80) << 8) | 0x7f;
    assert(reap() == -1 && !io_uring_setup_eperm_writes); failed = 1; cancel();
    assert(failed && cancelled && unit_kills == 1);
    printf("IO_URING_FALLBACK_UNIT_OK %u\n", unit_cases);
    return 0;
}
