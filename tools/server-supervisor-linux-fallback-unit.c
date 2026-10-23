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
static unsigned unit_ptraces, unit_clocks, unit_opens, unit_sends, unit_closes;
static int unit_get_failure, unit_event_old, unit_wait_status, unit_wait_tid, unit_wait_error;
static int unit_open_failure, unit_kill_error, unit_send_failure, unit_event_failure, unit_siginfo_failure;
static int unit_open_error, unit_resume_error;
static int unit_last_signal;
struct unit_wait { pid_t tid; int status, error; };
static struct unit_wait unit_wait_vector[16];
static unsigned unit_wait_length, unit_wait_index;
struct unit_resume { pid_t tid; enum __ptrace_request request; int forwarded; };
static struct unit_resume unit_resume_vector[16];
static unsigned unit_resume_length;
static void unit_resume_owned(enum __ptrace_request request, pid_t tid, int forwarded);
struct unit_io { const char *bytes; size_t length; ssize_t result; int error; unsigned calls; };
static struct unit_io unit_command_io, unit_source_io, unit_input_io, unit_event_io;
static char unit_events[1024];
static long unit_ptrace(enum __ptrace_request request, ...) {
    unit_ptraces++;
    va_list arguments; va_start(arguments, request);
    pid_t tid = va_arg(arguments, pid_t); assert(tid > 0);
    if (request == PTRACE_GET_SYSCALL_INFO) (void)va_arg(arguments, size_t);
    else (void)va_arg(arguments, void *);
    void *data = va_arg(arguments, void *); va_end(arguments);
    if (request == PTRACE_GET_SYSCALL_INFO) { memcpy(data, &unit_info, sizeof(unit_info)); return unit_info_size; }
    if (request == PTRACE_GETREGS) { if (unit_get_failure) { errno = EIO; return -1; } memcpy(data, &unit_regs, sizeof(unit_regs)); return 0; }
    if (request == PTRACE_SETREGS) {
        if (++unit_sets == unit_set_failure) { errno = EIO; return -1; }
        unit_regs = *(struct user_regs_struct *)data; return 0;
    }
    if (request == PTRACE_SYSCALL || request == PTRACE_CONT) {
        unit_last_signal = (int)(intptr_t)data;
        if (unit_wait_length) unit_resume_owned(request, tid, unit_last_signal);
        if (++unit_resumes == unit_resume_failure) { errno = unit_resume_error; return -1; }
        return 0;
    }
    if (request == PTRACE_GETEVENTMSG) { if (unit_event_failure) { errno = EIO; return -1; } *(unsigned long *)data = (unsigned long)unit_event_old; return 0; }
    if (request == PTRACE_GETSIGINFO) { if (unit_siginfo_failure) { errno = EIO; return -1; } memset(data, 0, sizeof(siginfo_t)); return 0; }
    unit_forbidden("ptrace");
}
static long unit_syscall(long number, ...) {
    va_list arguments; va_start(arguments, number);
    if (number == SYS_pidfd_send_signal) {
        int fd = va_arg(arguments, int), sig = va_arg(arguments, int);
        assert((fd == 9 || fd == 10) && sig == SIGKILL); unit_kills++; va_end(arguments);
        if (unit_kill_error) { errno = unit_kill_error; return -1; } return 0;
    }
    if (number == SYS_pidfd_open) {
        pid_t tid = va_arg(arguments, pid_t); int flags = va_arg(arguments, int);
        assert(tid > 0 && !flags); unit_opens++; va_end(arguments);
        if (unit_open_failure) { errno = unit_open_error; return -1; } return 10;
    }
    va_end(arguments); unit_forbidden("syscall");
}
static ssize_t unit_write(int fd, const void *bytes, size_t length) {
    if (fd == 11) {
        unit_input_io.calls++; assert(unit_input_io.result >= -1 && unit_input_io.result <= (ssize_t)length);
        if (unit_input_io.result < 0) errno = unit_input_io.error;
        return unit_input_io.result;
    }
    assert(fd == 1 && strlen(unit_events) + length < sizeof(unit_events)); unit_event_io.calls++;
    if (unit_event_io.result == -1) { errno = unit_event_io.error; return -1; }
    if (unit_event_io.result == 0) return 0;
    memcpy(unit_events + strlen(unit_events), bytes, length); return (ssize_t)length;
}
static ssize_t unit_read(int fd, void *bytes, size_t length) {
    assert(fd == 0 || fd == 5); struct unit_io *io = fd == 0 ? &unit_command_io : &unit_source_io;
    io->calls++; assert(io->result >= -1 && io->result <= (ssize_t)length);
    if (io->result < 0) errno = io->error;
    if (io->result > 0) { assert((size_t)io->result <= io->length); memcpy(bytes, io->bytes, (size_t)io->result); }
    return io->result;
}
static int unit_close(int fd) { assert(fd == 9 || fd == 10 || fd == 11 || fd == 5); unit_closes++; return 0; }
static int unit_fsync(int fd) { (void)fd; unit_forbidden("fsync"); }
static int unit_clock_gettime(clockid_t clock, struct timespec *value) { unit_clocks++; assert(clock == CLOCK_MONOTONIC); *value = (struct timespec){ .tv_sec = 100 }; return 0; }
static pid_t unit_getpid(void) { return 100; }
static pid_t unit_getppid(void) { unit_forbidden("getppid"); }
static uid_t unit_geteuid(void) { unit_forbidden("geteuid"); }
static ssize_t unit_sendmsg(int fd, const struct msghdr *message, int flags) {
    assert(fd == 6 && flags == (MSG_DONTWAIT | MSG_NOSIGNAL) && message->msg_iovlen == 1);
    unit_sends++; if (unit_send_failure) { errno = EIO; return -1; } return (ssize_t)message->msg_iov[0].iov_len;
}
static pid_t unit_waitpid(pid_t pid, int *status, int options) {
    assert(pid == -1 && options == (__WALL | WNOHANG));
    if (unit_wait_length) {
        unit_waits++; assert(unit_wait_length <= 16 && unit_wait_index < unit_wait_length);
        struct unit_wait row = unit_wait_vector[unit_wait_index++];
        *status = row.status; if (row.tid < 0) errno = row.error; return row.tid;
    }
    if (unit_waits++ == 0) { *status = unit_wait_status; if (unit_wait_tid < 0) errno = unit_wait_error; return unit_wait_tid; }
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

static void unit_resume_owned(enum __ptrace_request request, pid_t tid, int forwarded) {
    struct task *t = task(tid);
    assert(t && group(t->tgid) && !t->newborn && unit_resume_length < 16);
    if (tid == 102 && (unit_resume_length == 0 || unit_resume_length == 1)) {
        assert(task(root) && task(root)->born && (task(root)->pending == PROCESS || task(root)->pending == THREAD));
        assert(!pending && !early_stops[0] && !early_exit_seen[0]);
        assert(t->tgid == (task(root)->pending == THREAD ? root : 102));
    }
    unit_resume_vector[unit_resume_length++] = (struct unit_resume){ .tid = tid, .request = request, .forwarded = forwarded };
}

static unsigned unit_cases;
static struct task *unit_begin(void) {
    unit_cases++;
    memset(tasks, 0, sizeof(tasks)); memset(groups, 0, sizeof(groups)); memset(early_stops, 0, sizeof(early_stops));
    memset(early_exit_seen, 0, sizeof(early_exit_seen));
    memset(denial_buckets, 0, sizeof(denial_buckets)); memset(&unit_info, 0, sizeof(unit_info)); memset(&unit_regs, 0, sizeof(unit_regs));
    memset(unit_events, 0, sizeof(unit_events));
    live = births = peak = 1; pending = reservations = failed_creations = denied = clone3_fallbacks = waits = exec_retired = thread_births = 0;
    io_uring_setup_attempts = io_uring_setup_eperm_writes = eperm_register_writes = eagain_register_writes = 0;
    failed = cancelled = root_terminal = root_code = root_signal = root_wait_status = closure_echild = 0;
    first_seen = first_site = owner_eof = shutting_down = input_settled = source_eof = input_bytes = written_bytes = command_count = interrupted = 0;
    input_write = 11; witness = -1; end = 123 * NS;
    ready = launched = started = 1; limit = 8; root = 101; groups[0] = (struct group){ .tgid = root, .fd = 9 };
    unit_sets = unit_resumes = unit_set_failure = unit_resume_failure = unit_kills = unit_waits = 0;
    unit_get_failure = unit_event_old = unit_wait_status = unit_wait_tid = 0;
    unit_ptraces = unit_clocks = unit_opens = unit_sends = unit_closes = 0;
    unit_wait_length = unit_wait_index = unit_resume_length = 0;
    memset(unit_wait_vector, 0, sizeof(unit_wait_vector)); memset(unit_resume_vector, 0, sizeof(unit_resume_vector));
    unit_open_failure = unit_kill_error = unit_send_failure = unit_event_failure = unit_siginfo_failure = unit_wait_error = unit_last_signal = 0;
    unit_open_error = unit_resume_error = EIO;
    unit_command_io = unit_source_io = unit_input_io = (struct unit_io){ .result = -2 };
    unit_event_io = (struct unit_io){ .result = 1 }; errno = 0;
    unit_info.op = PTRACE_SYSCALL_INFO_SECCOMP; unit_info.arch = AUDIT_ARCH_X86_64; unit_info.seccomp.nr = SYS_io_uring_setup;
    unit_regs.orig_rax = SYS_io_uring_setup; unit_regs.rdi = 1; unit_regs.rsi = 1; unit_info_size = sizeof(unit_info);
    return add_task(root, root, 0);
}
static void unit_complete(struct task *t) {
    unit_info.op = PTRACE_SYSCALL_INFO_EXIT; unit_info.exit.is_error = 1;
    assert(syscall_exit(t) == 0 && t->pending == NONE);
    assert(unit_regs.rax == (unsigned long long)-(long long)EPERM);
}
static void unit_site(int site) { assert(first_seen == 1 && first_site == site && !unit_clocks); }
static void unit_latch_contract(void) {
    struct task *t = unit_begin();
    struct task before = *t; struct group held = groups[0]; struct user_regs_struct regs = unit_regs;
    errno = EDOM;
    assert(failure_mark(N_UNKNOWN) == -1 && errno == EDOM); unit_site(N_UNKNOWN);
    assert(failure_observed(N_CANCEL_SIGNAL) == 1 && errno == EDOM); unit_site(N_UNKNOWN);
    assert(!failed && !cancelled && !denied && live == 1 && births == 1 && peak == 1 && !pending && !reservations);
    assert(!failed_creations && !waits && !exec_retired && !thread_births && !clone3_fallbacks && !command_count && !interrupted);
    for (unsigned i = 0; i < D_BUCKETS; i++) assert(!denial_buckets[i]);
    assert(!io_uring_setup_attempts && !io_uring_setup_eperm_writes && !eperm_register_writes && !eagain_register_writes);
    assert(!unit_ptraces && !unit_kills && !unit_opens && !unit_sends && !unit_waits && !unit_closes && !unit_event_io.calls);
    assert(!memcmp(t, &before, sizeof(before)) && !memcmp(&groups[0], &held, sizeof(held)) && !memcmp(&unit_regs, &regs, sizeof(regs)));
    assert(ready && launched && started && !root_terminal && root == 101 && end == 123 * NS && witness == -1 && input_write == 11);
    t = unit_begin(); errno = EDOM; assert(failure_observed(N_UNKNOWN) == 1 && errno == EDOM); unit_site(N_UNKNOWN);
    assert(failure_mark(N_RESERVE) == -1 && first_site == N_UNKNOWN);
    unit_info_size = 0; assert(gate(t) == -1); unit_site(N_UNKNOWN);
    t = unit_begin(); assert(failure_mark(N_INPUT_READ) == -1); unit_kill_error = EIO; cancel();
    unit_site(N_INPUT_READ); assert(failed && cancelled && unit_kills == 1 && errno == EIO);
    t = unit_begin(); unit_kill_error = EIO; cancel(); unit_site(N_CANCEL_SIGNAL); assert(failed && cancelled && unit_kills == 1);
    t = unit_begin(); unit_kill_error = ESRCH; cancel(); assert(!first_seen && !failed && cancelled && unit_kills == 1);
    t = unit_begin(); denied = INT32_MAX; errno = EDOM;
    assert(admission_increment(&denied) == -1 && denied == INT32_MAX && failed && !cancelled && errno == EDOM);
    unit_site(N_COUNTER_BOUND); assert(!unit_ptraces && !unit_kills && !unit_opens && !unit_waits);
    (void)t;
}
static void unit_wait_and_group_contract(void) {
    struct task *t = unit_begin(); unit_wait_tid = -1; unit_wait_error = EINTR;
    assert(reap() == 0 && !first_seen && unit_waits == 2 && !unit_ptraces);
    t = unit_begin(); unit_wait_tid = -1; unit_wait_error = EIO;
    assert(reap() == -1 && errno == EIO && unit_waits == 1); unit_site(N_WAIT_RESULT);
    t = unit_begin(); memset(tasks, 0, sizeof(tasks)); live = 0; unit_wait_tid = -1; unit_wait_error = ECHILD;
    assert(reap() == 0 && closure_echild && !first_seen && unit_waits == 1 && !unit_ptraces);
    t = unit_begin(); live = 0; unit_wait_tid = -1; unit_wait_error = ECHILD;
    assert(reap() == -1 && !closure_echild); unit_site(N_WAIT_REGISTRY);
    t = unit_begin(); unit_wait_tid = 102; unit_wait_status = 0;
    assert(reap() == -1 && unit_waits == 1); unit_site(N_WAIT_REGISTRY);
    t = unit_begin(); unit_wait_tid = 102; unit_wait_status = (SIGUSR1 << 8) | 0x7f;
    assert(reap() == -1 && !unit_ptraces); unit_site(N_EARLY_STOP_SIGNAL_OTHER);
    t = unit_begin(); unit_wait_tid = root; unit_wait_status = (SIGUSR1 << 8) | 0x7f;
    assert(reap() == 0 && !first_seen && unit_ptraces == 2 && unit_resumes == 1 && unit_last_signal == SIGUSR1);
    t = unit_begin(); unit_wait_tid = root; unit_wait_status = (SIGUSR1 << 8) | 0x7f; unit_siginfo_failure = 1;
    assert(reap() == -1 && errno == EIO && unit_ptraces == 1 && !unit_resumes); unit_site(N_SIGNAL_INFO);
    t = unit_begin(); t->pending = THREAD; unit_info_size = 0;
    unit_wait_tid = root; unit_wait_status = (PTRACE_EVENT_SECCOMP << 16) | (SIGTRAP << 8) | 0x7f;
    assert(reap() == -1 && unit_ptraces == 1 && !failed && !cancelled); unit_site(N_GATE_METADATA);
    t = unit_begin(); errno = EDOM; assert(add_group(root) == -1 && errno == EDOM && !unit_opens && live == 1 && births == 1); unit_site(N_GROUP_ADMISSION);
    t = unit_begin(); unit_open_failure = 1; assert(add_group(102) == -1 && errno == EIO && unit_opens == 1 && live == 1); unit_site(N_GROUP_ADMISSION);
    t = unit_begin(); witness = 6; unit_send_failure = 1;
    assert(add_group(102) == -1 && unit_opens == 1 && unit_sends == 1 && live == 2 && births == 2 && peak == 2 && errno == EIO); unit_site(N_GROUP_ADMISSION);
    t = unit_begin(); t->pending = PROCESS; pending = reservations = 1; unit_event_old = 102; unit_open_failure = 1;
    assert(birth(t) == -1 && pending == 0 && reservations == 1 && task(102) && !t->born && unit_ptraces == 1 && unit_opens == 1); unit_site(N_GROUP_ADMISSION);
    t = unit_begin(); t->pending = PROCESS; unit_event_old = 102;
    assert(birth(t) == -1 && task(102) && !unit_opens); unit_site(N_BIRTH_RESERVATION);
    t = unit_begin(); t->pending = THREAD; unit_event_old = root;
    assert(birth(t) == -1 && unit_ptraces == 1); unit_site(N_BIRTH_TASK);
    t = unit_begin(); unit_event_failure = 1;
    assert(exec_event(root) == -1 && unit_ptraces == 1 && errno == EIO); unit_site(N_EXEC_METADATA);
    t = unit_begin(); unit_event_old = 102; assert(exec_event(root) == -1 && unit_ptraces == 1); unit_site(N_EXEC_ACCOUNTING);
    t = unit_begin(); assert(birth(t) == -1 && !unit_ptraces); unit_site(N_BIRTH_METADATA);
    t = unit_begin(); launched = 0; assert(gate(t) == -1 && unit_ptraces == 2 && !denied); unit_site(N_GATE_NOT_LAUNCHED);
    t = unit_begin(); t->pending = PROCESS; assert(terminal(t, 0) == -1 && !unit_closes); unit_site(N_TERMINAL_ACCOUNTING);
    t = unit_begin(); t->pending = THREAD; unit_info.op = PTRACE_SYSCALL_INFO_EXIT; unit_info.exit.is_error = 1; t->born = 1;
    assert(syscall_exit(t) == -1 && unit_ptraces == 1); unit_site(N_EXIT_BIRTH_ACCOUNTING);
    t = unit_begin(); assert(terminal(t, SIGSYS) == 0 && failed); unit_site(N_TERMINAL_SIGSYS);
    assert(strstr(unit_events, "EXITED 101 -1 31 0 32\n") != NULL);
    t = unit_begin(); pending = 1;
    assert(terminal(t, 0) == 0 && failed && cancelled && !unit_kills); unit_site(N_ROOT_DESCENDANTS);
    assert(!strcmp(unit_events, "EXITED 101 0 0 0 0\n")); // No retrospective site33 emission.
    t = unit_begin(); assert(failure_mark(N_UNKNOWN) == -1); unit_event_io.result = -1; unit_event_io.error = EPIPE;
    assert(event("STARTED 101\n") == -1 && failed && cancelled && errno == EPIPE && unit_event_io.calls == 1); unit_site(N_UNKNOWN);
    t = unit_begin(); unit_event_io.result = 0; assert(event("X\n") == -1 && failed && cancelled); unit_site(N_EVENT_WRITE);
    t = unit_begin(); unit_info.seccomp.nr = unit_regs.orig_rax = SYS_fork;
    assert(gate(t) == 0 && pending == 1 && reservations == 1 && !first_seen);
    unit_info.op = PTRACE_SYSCALL_INFO_EXIT; unit_info.exit.is_error = 1;
    assert(syscall_exit(t) == 0 && failed_creations == 1 && !pending && t->pending == NONE && !failed && !first_seen);
    assert(unit_ptraces == 5 && unit_resumes == 2 && !unit_clocks);
}
static void unit_birth_wait_vectors(void) {
    for (unsigned thread = 0; thread < 2; thread++) for (unsigned child_first = 0; child_first < 2; child_first++) {
        struct task *parent = unit_begin();
        parent->pending = thread ? THREAD : PROCESS;
        pending = reservations = thread ? 0 : 1; peak = thread ? 1 : 2;
        unit_event_old = 102; unit_info.op = PTRACE_SYSCALL_INFO_EXIT; unit_info.exit.is_error = 0;
        struct unit_wait child_stop = { .tid = 102, .status = (SIGSTOP << 8) | 0x7f };
        struct unit_wait parent_birth = { .tid = root, .status = ((thread ? PTRACE_EVENT_CLONE : PTRACE_EVENT_FORK) << 16) | (SIGTRAP << 8) | 0x7f };
        unit_wait_vector[0] = child_first ? child_stop : parent_birth;
        unit_wait_vector[1] = child_first ? parent_birth : child_stop;
        unit_wait_vector[2] = (struct unit_wait){ .tid = root, .status = ((SIGTRAP | 0x80) << 8) | 0x7f };
        unit_wait_vector[3] = (struct unit_wait){ .tid = 102, .status = (PTRACE_EVENT_EXIT << 16) | (SIGTRAP << 8) | 0x7f };
        unit_wait_vector[4] = (struct unit_wait){ .tid = 102, .status = 0 };
        unit_wait_vector[5] = (struct unit_wait){ .tid = root, .status = (PTRACE_EVENT_EXIT << 16) | (SIGTRAP << 8) | 0x7f };
        unit_wait_vector[6] = (struct unit_wait){ .tid = root, .status = 0 };
        unit_wait_vector[7] = (struct unit_wait){ .tid = -1, .error = ECHILD };
        unit_wait_length = 8;
        struct user_regs_struct regs = unit_regs;
        assert(reap() == 0 && closure_echild && root_terminal && !failed && !cancelled && !first_seen);
        assert(unit_waits == 8 && unit_wait_index == 8 && unit_ptraces == 7 && unit_resumes == 5 && unit_resume_length == 5);
        assert(!live && !pending && reservations == (thread ? 0U : 1U) && births == (thread ? 1U : 2U));
        assert(peak == (thread ? 1U : 2U) && thread_births == thread && waits == 2 && !exec_retired && !failed_creations);
        assert(unit_opens == (thread ? 0U : 1U) && unit_closes == (thread ? 1U : 2U));
        assert(!unit_kills && !unit_clocks && !unit_sends && !unit_sets && !denied && !clone3_fallbacks);
        assert(!io_uring_setup_attempts && !io_uring_setup_eperm_writes && !eperm_register_writes && !eagain_register_writes);
        assert(!memcmp(&unit_regs, &regs, sizeof(regs)) && unit_event_io.calls == 1 && !strcmp(unit_events, "EXITED 101 0 0 0 0\n"));
        assert(unit_resume_vector[0].tid == (child_first ? 102 : root));
        assert(unit_resume_vector[0].request == (child_first ? PTRACE_CONT : PTRACE_SYSCALL));
        assert(unit_resume_vector[1].tid == (child_first ? root : 102));
        assert(unit_resume_vector[1].request == (child_first ? PTRACE_SYSCALL : PTRACE_CONT));
        assert(unit_resume_vector[2].tid == root && unit_resume_vector[2].request == PTRACE_CONT);
        assert(unit_resume_vector[3].tid == 102 && unit_resume_vector[3].request == PTRACE_CONT);
        assert(unit_resume_vector[4].tid == root && unit_resume_vector[4].request == PTRACE_CONT);
        for (unsigned i = 0; i < unit_resume_length; i++) assert(!unit_resume_vector[i].forwarded);
        for (unsigned i = 0; i < TASKS; i++) assert(!tasks[i].tid && !early_stops[i] && !early_exit_seen[i]);
        for (unsigned i = 0; i < GROUPS; i++) assert(!groups[i].tgid);
    }
}
static void unit_rejected_wait(unsigned ev, int sig, int queued, int prior, int expected) {
    struct task *t = unit_begin();
    if (prior == N_UNKNOWN) { io_uring_setup_attempts = 1; fallback_closure(); }
    else if (prior == N_POLICY_DENIAL) {
        unit_info.seccomp.nr = unit_regs.orig_rax = SYS_ptrace;
        assert(gate(t) == 0 && failed && denied == 1 && t->pending == REJECT);
    } else assert(prior == -1);
    if (queued == 1) early_stops[0] = 102;
    else if (queued == 2) for (unsigned i = 0; i < TASKS; i++) { early_stops[i] = (pid_t)(1000 + i); early_exit_seen[i] = 1; }
    else if (queued == 3) early_exit_seen[0] = 1; // A stale bit alone never proves a held PID.
    else assert(!queued);
    struct task before_tasks[TASKS]; struct group before_groups[GROUPS]; pid_t before_early[TASKS];
    unsigned char before_exit_seen[TASKS];
    memcpy(before_tasks, tasks, sizeof(tasks)); memcpy(before_groups, groups, sizeof(groups)); memcpy(before_early, early_stops, sizeof(early_stops));
    memcpy(before_exit_seen, early_exit_seen, sizeof(early_exit_seen));
    struct user_regs_struct regs = unit_regs;
    unsigned before_ptraces = unit_ptraces, before_resumes = unit_resumes, before_sets = unit_sets;
    unsigned before_denied = denied, before_attempts = io_uring_setup_attempts;
    int before_failed = failed;
    unit_wait_vector[0] = (struct unit_wait){ .tid = 102, .status = (int)(ev << 16) | (sig << 8) | 0x7f };
    unit_wait_length = 1; errno = EDOM;
    assert(reap() == -1 && errno == EDOM); unit_site(expected);
    assert(unit_waits == 1 && unit_wait_index == 1 && !unit_resume_length && !unit_clocks && !unit_opens && !unit_kills && !unit_sends && !unit_closes);
    assert(unit_ptraces == before_ptraces && unit_resumes == before_resumes && unit_sets == before_sets && !unit_event_io.calls);
    assert(failed == before_failed && !cancelled && live == 1 && births == 1 && peak == 1 && !pending && !reservations);
    assert(denied == before_denied && io_uring_setup_attempts == before_attempts && !io_uring_setup_eperm_writes && !eperm_register_writes && !eagain_register_writes);
    assert(!waits && !failed_creations && !thread_births && !exec_retired && !clone3_fallbacks && ready && launched && started && !root_terminal && !closure_echild);
    assert(!memcmp(tasks, before_tasks, sizeof(tasks)) && !memcmp(groups, before_groups, sizeof(groups)) && !memcmp(early_stops, before_early, sizeof(early_stops)));
    assert(!memcmp(early_exit_seen, before_exit_seen, sizeof(early_exit_seen)));
    assert(!memcmp(&unit_regs, &regs, sizeof(regs)) && !task(102));
}
static void unit_early_rejection_vectors(void) {
    const struct { unsigned ev; int sig, queued, site; } cases[] = {
        { 0, SIGSTOP, 1, N_EARLY_STOP_DUPLICATE }, { 0, SIGSTOP, 2, N_EARLY_STOP_CAPACITY },
        { PTRACE_EVENT_FORK, SIGTRAP, 0, N_EARLY_STOP_EVENT_FORK },
        { PTRACE_EVENT_VFORK, SIGTRAP, 0, N_EARLY_STOP_EVENT_VFORK },
        { PTRACE_EVENT_CLONE, SIGTRAP, 0, N_EARLY_STOP_EVENT_CLONE },
        { PTRACE_EVENT_EXIT, SIGUSR1, 0, N_EARLY_STOP_EVENT_EXIT },
        { PTRACE_EVENT_EXIT, SIGSTOP, 1, N_EARLY_STOP_EVENT_EXIT },
        { PTRACE_EVENT_EXIT, SIGTRAP | 0x80, 1, N_EARLY_STOP_EVENT_EXIT },
        { PTRACE_EVENT_SECCOMP, SIGTRAP, 1, N_EARLY_STOP_EVENT_SECCOMP },
        { PTRACE_EVENT_EXIT, SIGTRAP, 2, N_EARLY_STOP_CAPACITY },
        { PTRACE_EVENT_EXIT, SIGSTOP, 3, N_EARLY_STOP_EVENT_EXIT },
        { PTRACE_EVENT_SECCOMP, SIGTRAP, 0, N_EARLY_STOP_EVENT_SECCOMP },
        { PTRACE_EVENT_STOP, SIGTRAP, 0, N_EARLY_STOP_EVENT_STOP },
        { 123, SIGTRAP, 0, N_EARLY_STOP_EVENT_OTHER },
        { 0, SIGTRAP | 0x80, 0, N_EARLY_STOP_SIGNAL_SYSCALL_TRAP },
        { 0, SIGTRAP, 0, N_EARLY_STOP_SIGNAL_TRAP },
        { 0, SIGTSTP, 0, N_EARLY_STOP_SIGNAL_JOB_CONTROL },
        { 0, SIGURG, 0, N_EARLY_STOP_SIGNAL_WAKEUP_OR_PROFILE },
        { 0, SIGTERM, 0, N_EARLY_STOP_SIGNAL_TERMINATION }
    };
    _Static_assert(N_CANCEL_SIGNAL == 38 && N_EARLY_STOP_DUPLICATE == 39 && N_EARLY_STOP_SIGNAL_OTHER == 53, "stable diagnostic IDs");
    _Static_assert(sizeof(cases) / sizeof(cases[0]) == 19, "finite rejection fixtures");
    for (unsigned i = 0; i < sizeof(cases) / sizeof(cases[0]); i++) unit_rejected_wait(cases[i].ev, cases[i].sig, cases[i].queued, -1, cases[i].site);
    unit_rejected_wait(0, SIGSTOP, 1, N_UNKNOWN, N_UNKNOWN);
    unit_rejected_wait(PTRACE_EVENT_EXIT, SIGSTOP, 0, N_POLICY_DENIAL, N_POLICY_DENIAL);
    // Additional group members exercise the same pure classifier without a new case/reset.
    errno = EDOM;
    assert(early_stop_site(0, SIGTTIN) == N_EARLY_STOP_SIGNAL_JOB_CONTROL && early_stop_site(0, SIGTTOU) == N_EARLY_STOP_SIGNAL_JOB_CONTROL);
    assert(early_stop_site(0, SIGCONT) == N_EARLY_STOP_SIGNAL_JOB_CONTROL && early_stop_site(0, SIGPROF) == N_EARLY_STOP_SIGNAL_WAKEUP_OR_PROFILE);
    assert(early_stop_site(0, SIGHUP) == N_EARLY_STOP_SIGNAL_TERMINATION && early_stop_site(0, SIGINT) == N_EARLY_STOP_SIGNAL_TERMINATION);
    assert(early_stop_site(0, SIGQUIT) == N_EARLY_STOP_SIGNAL_TERMINATION && early_stop_site(0, SIGUSR2) == N_EARLY_STOP_SIGNAL_OTHER);
    assert(errno == EDOM && !unit_clocks);
}
static void unit_hold_wait(unsigned ev, int sig, unsigned exit_seen) {
    struct task before_tasks[TASKS]; struct group before_groups[GROUPS]; struct user_regs_struct regs = unit_regs;
    memcpy(before_tasks, tasks, sizeof(tasks)); memcpy(before_groups, groups, sizeof(groups));
    int before_seen = first_seen, before_site = first_site;
    unsigned before_waits = unit_waits;
    unit_wait_vector[0] = (struct unit_wait){ .tid = 102, .status = (int)(ev << 16) | (sig << 8) | 0x7f };
    unit_wait_vector[1] = (struct unit_wait){ .tid = 0 }; unit_wait_length = 2; unit_wait_index = 0; errno = EDOM;
    assert(reap() == 0 && errno == EDOM && unit_wait_index == 2 && unit_waits == before_waits + 2);
    assert(early_stops[0] == 102 && early_exit_seen[0] == exit_seen && !task(102) && !group(102));
    for (unsigned i = 1; i < TASKS; i++) assert(!early_stops[i] && !early_exit_seen[i]);
    assert(!memcmp(tasks, before_tasks, sizeof(tasks)) && !memcmp(groups, before_groups, sizeof(groups)) && !memcmp(&unit_regs, &regs, sizeof(regs)));
    int thread = task(root)->pending == THREAD;
    assert(task(root)->pending == PROCESS || thread);
    assert(live == 1 && births == 1 && peak == (thread ? 1U : 2U) && pending == (thread ? 0U : 1U) && reservations == pending && !task(root)->born);
    assert(!waits && !failed_creations && !thread_births && !exec_retired && !denied && !clone3_fallbacks && !failed && !cancelled && !closure_echild && !root_terminal);
    assert(first_seen == before_seen && first_site == before_site && !io_uring_setup_attempts && !io_uring_setup_eperm_writes && !eperm_register_writes && !eagain_register_writes);
    assert(!unit_ptraces && !unit_resumes && !unit_resume_length && !unit_sets && !unit_kills && !unit_opens && !unit_closes && !unit_sends && !unit_clocks && !unit_event_io.calls);
}
static struct task *unit_held_process(int prior) {
    struct task *parent = unit_begin(); parent->pending = PROCESS; pending = reservations = 1; peak = 2;
    unit_event_old = 102; unit_info.op = PTRACE_SYSCALL_INFO_EXIT; unit_info.exit.is_error = 0;
    if (prior >= 0) assert(failure_mark(prior) == -1);
    unit_hold_wait(0, SIGSTOP, 0); unit_hold_wait(PTRACE_EVENT_EXIT, SIGTRAP, 1);
    return parent;
}
static struct task *unit_first_exit(unsigned thread, int prior) {
    struct task *parent = unit_begin(); parent->pending = thread ? THREAD : PROCESS;
    pending = reservations = thread ? 0 : 1; peak = thread ? 1 : 2;
    unit_event_old = 102; unit_info.op = PTRACE_SYSCALL_INFO_EXIT; unit_info.exit.is_error = 0;
    if (prior >= 0) assert(failure_mark(prior) == -1);
    unit_hold_wait(PTRACE_EVENT_EXIT, SIGTRAP, 1);
    return parent;
}
static void unit_held_exit_success(void) {
    // The THREAD first-EXIT vector checks synthetic bookkeeping, not fatal-signal kernel ordering.
    for (unsigned scenario = 0; scenario < 3; scenario++) {
        unsigned thread = scenario == 2;
        if (!scenario) unit_held_process(-1); else unit_first_exit(thread, -1);
        struct user_regs_struct regs = unit_regs;
        unit_wait_vector[0] = (struct unit_wait){ .tid = root, .status = ((thread ? PTRACE_EVENT_CLONE : PTRACE_EVENT_FORK) << 16) | (SIGTRAP << 8) | 0x7f };
        unit_wait_vector[1] = (struct unit_wait){ .tid = root, .status = ((SIGTRAP | 0x80) << 8) | 0x7f };
        unit_wait_vector[2] = (struct unit_wait){ .tid = 102, .status = thread ? 0 : SIGKILL };
        unit_wait_vector[3] = (struct unit_wait){ .tid = root, .status = (PTRACE_EVENT_EXIT << 16) | (SIGTRAP << 8) | 0x7f };
        unit_wait_vector[4] = (struct unit_wait){ .tid = root, .status = 0 };
        unit_wait_vector[5] = (struct unit_wait){ .tid = -1, .error = ECHILD }; unit_wait_length = 6; unit_wait_index = 0;
        assert(reap() == 0 && closure_echild && root_terminal && !failed && !cancelled && !first_seen);
        assert(unit_waits == (scenario ? 8U : 10U) && unit_wait_index == 6 && unit_ptraces == 6 && unit_resumes == 4 && unit_resume_length == 4);
        assert(!live && !pending && reservations == (thread ? 0U : 1U) && births == (thread ? 1U : 2U));
        assert(peak == (thread ? 1U : 2U) && waits == 2 && thread_births == thread && !exec_retired && !failed_creations);
        assert(unit_opens == (thread ? 0U : 1U) && unit_closes == (thread ? 1U : 2U) && !unit_kills && !unit_clocks && !unit_sends && !unit_sets && !denied && !clone3_fallbacks);
        assert(!io_uring_setup_attempts && !io_uring_setup_eperm_writes && !eperm_register_writes && !eagain_register_writes && !memcmp(&unit_regs, &regs, sizeof(regs)));
        assert(unit_event_io.calls == 1 && !strcmp(unit_events, "EXITED 101 0 0 0 0\n"));
        assert(unit_resume_vector[0].tid == 102 && unit_resume_vector[0].request == PTRACE_CONT);
        assert(unit_resume_vector[1].tid == root && unit_resume_vector[1].request == PTRACE_SYSCALL);
        for (unsigned i = 2; i < 4; i++) assert(unit_resume_vector[i].tid == root && unit_resume_vector[i].request == PTRACE_CONT);
        for (unsigned i = 0; i < 4; i++) assert(!unit_resume_vector[i].forwarded);
        for (unsigned i = 0; i < TASKS; i++) assert(!tasks[i].tid && !early_stops[i] && !early_exit_seen[i]);
        for (unsigned i = 0; i < GROUPS; i++) assert(!groups[i].tgid);
    }
}
static void unit_held_exit_rejections(void) {
    const int sites[] = { N_EARLY_STOP_EVENT_EXIT, N_BIRTH_METADATA, N_WAIT_REGISTRY, N_BIRTH_RESERVATION,
        N_GROUP_ADMISSION, N_WAIT_REGISTRY, N_WAIT_REGISTRY, N_BIRTH_TASK, N_EARLY_STOP_DUPLICATE, N_EARLY_STOP_EVENT_FORK,
        N_RESUME, N_RESUME, N_GROUP_ADMISSION, N_RESUME };
    _Static_assert(sizeof(sites) / sizeof(sites[0]) == 14, "finite held/first-EXIT refusal fixtures");
    for (unsigned first_exit = 0; first_exit < 2; first_exit++) for (unsigned fault = 0; fault < sizeof(sites) / sizeof(sites[0]); fault++) {
        struct task *parent = first_exit ? unit_first_exit(0, -1) : unit_held_process(-1); struct user_regs_struct regs = unit_regs;
        unit_wait_vector[0] = (struct unit_wait){ .tid = root, .status = (PTRACE_EVENT_FORK << 16) | (SIGTRAP << 8) | 0x7f };
        unit_wait_length = 1; unit_wait_index = 0;
        if (fault == 0) unit_wait_vector[0].tid = 102, unit_wait_vector[0].status = (PTRACE_EVENT_EXIT << 16) | (SIGTRAP << 8) | 0x7f;
        if (fault == 1) parent->pending = NONE;
        if (fault == 2) {
            unit_event_old = 103; unit_wait_vector[1] = (struct unit_wait){ .tid = 102, .status = SIGKILL }; unit_wait_length = 2;
        }
        if (fault == 3) pending = 0;
        if (fault == 4) unit_open_failure = 1;
        if (fault == 5) unit_wait_vector[0] = (struct unit_wait){ .tid = 102, .status = SIGKILL };
        if (fault == 6) {
            unit_wait_vector[0] = (struct unit_wait){ .tid = root, .status = SIGKILL };
            unit_wait_vector[1] = (struct unit_wait){ .tid = -1, .error = ECHILD }; unit_wait_length = 2;
        }
        if (fault == 7) assert(add_task(102, 102, 1));
        if (fault == 8) unit_wait_vector[0] = (struct unit_wait){ .tid = 102, .status = (SIGSTOP << 8) | 0x7f };
        if (fault == 9) unit_wait_vector[0].tid = 103;
        if (fault == 10) unit_resume_failure = 1;
        if (fault == 11) { unit_resume_failure = 1; unit_resume_error = ESRCH; }
        if (fault == 12) { unit_open_failure = 1; unit_open_error = ESRCH; }
        if (fault == 13) { unit_resume_failure = 2; unit_resume_error = ESRCH; }
        int expected_errno = fault == 4 || fault == 10 ? EIO : fault == 11 || fault == 12 || fault == 13 ? ESRCH : fault == 6 ? ECHILD : EDOM;
        errno = EDOM; assert(reap() == -1 && errno == expected_errno); unit_site(sites[fault]);
        int admitted = fault == 10 || fault == 11 || fault == 13;
        assert(!closure_echild && !failed && !cancelled);
        if (admitted) assert(!early_stops[0] && !early_exit_seen[0] && group(102) && task(102) && !task(102)->newborn && parent->born);
        else assert(early_stops[0] == 102 && early_exit_seen[0] == 1 && !group(102));
        for (unsigned i = 1; i < TASKS; i++) assert(!early_stops[i] && !early_exit_seen[i]);
        const unsigned ptraces[] = { 0, 0, 2, 1, 1, 0, 0, 1, 0, 0, 2, 2, 1, 3 };
        assert(unit_ptraces == ptraces[fault] && unit_resumes == (fault == 13 ? 2U : fault == 2 || admitted ? 1U : 0U) && unit_resume_length == unit_resumes);
        assert(unit_waits == (first_exit ? 2U : 4U) + unit_wait_length && unit_wait_index == unit_wait_length);
        assert(unit_opens == (fault == 2 || fault == 4 || fault == 12 || admitted ? 1U : 0U));
        assert(unit_closes == (fault == 6 ? 1U : 0U) && unit_event_io.calls == (fault == 6 ? 1U : 0U));
        assert(live == (fault == 2 || admitted ? 2U : fault == 6 ? 0U : 1U) && births == (fault == 2 || admitted ? 2U : 1U) && peak == 2 && reservations == 1);
        assert(pending == (fault == 2 || fault == 3 || fault == 4 || fault == 6 || fault == 12 || admitted ? 0U : 1U));
        assert(waits == (fault == 6 ? 1U : 0U) && failed_creations == (fault == 6 ? 1U : 0U) && root_terminal == (fault == 6));
        assert(!unit_sets && !unit_kills && !unit_clocks && !unit_sends && !thread_births && !exec_retired && !denied && !clone3_fallbacks);
        assert(!io_uring_setup_attempts && !io_uring_setup_eperm_writes && !eperm_register_writes && !eagain_register_writes && !memcmp(&unit_regs, &regs, sizeof(regs)));
        if (fault == 2) assert(!task(102) && task(103) && task(103)->newborn && group(103) && parent->born && unit_resume_vector[0].tid == root);
        else if (fault == 3 || fault == 4 || fault == 7 || fault == 12) assert(task(102) && task(102)->newborn && !parent->born);
        else if (!admitted) assert(!task(102));
        if (admitted) assert(unit_resume_vector[0].tid == 102 && unit_resume_vector[0].request == PTRACE_CONT && !unit_resume_vector[0].forwarded);
        if (fault == 13) assert(unit_resume_vector[1].tid == root && unit_resume_vector[1].request == PTRACE_SYSCALL && !unit_resume_vector[1].forwarded);
        if (fault == 6) assert(!task(root) && !strcmp(unit_events, "EXITED 101 -1 9 0 0\n"));
    }
    unit_begin();
    for (unsigned i = 0; i < TASKS; i++) { early_stops[i] = (pid_t)(1000 + i); early_exit_seen[i] = 1; }
    early_stops[TASKS - 1] = 102; early_exit_seen[TASKS - 1] = 0;
    unit_wait_vector[0] = (struct unit_wait){ .tid = 102, .status = (PTRACE_EVENT_EXIT << 16) | (SIGTRAP << 8) | 0x7f };
    unit_wait_vector[1] = (struct unit_wait){ .tid = 0 }; unit_wait_length = 2; errno = EDOM;
    assert(reap() == 0 && errno == EDOM && !first_seen && early_exit_seen[TASKS - 1] == 1);
    unit_wait_length = 1; unit_wait_index = 0;
    assert(reap() == -1 && errno == EDOM); unit_site(N_EARLY_STOP_EVENT_EXIT);
    for (unsigned i = 0; i < TASKS; i++) assert(early_stops[i] == (i == TASKS - 1 ? 102 : (pid_t)(1000 + i)) && early_exit_seen[i] == 1);
    assert(!task(102) && !group(102) && live == 1 && births == 1 && peak == 1 && !pending && !reservations && !waits && !failed && !cancelled && !closure_echild);
    assert(unit_waits == 3 && !unit_ptraces && !unit_resumes && !unit_resume_length && !unit_sets && !unit_kills && !unit_clocks && !unit_opens && !unit_closes && !unit_sends && !unit_event_io.calls);
    for (unsigned first_exit = 0; first_exit < 2; first_exit++) for (unsigned prior = 0; prior < 2; prior++) {
        int site = prior ? N_POLICY_DENIAL : N_UNKNOWN;
        if (first_exit) unit_first_exit(0, site); else unit_held_process(site);
        unit_wait_vector[0] = (struct unit_wait){ .tid = 102, .status = (PTRACE_EVENT_EXIT << 16) | (SIGTRAP << 8) | 0x7f };
        unit_wait_length = 1; unit_wait_index = 0; errno = EDOM;
        assert(reap() == -1 && errno == EDOM && early_stops[0] == 102 && early_exit_seen[0] == 1); unit_site(site);
        assert(!task(102) && !group(102) && pending == 1 && reservations == 1 && live == 1 && births == 1 && !failed && !cancelled && !waits);
        assert(unit_waits == (first_exit ? 3U : 5U) && !unit_ptraces && !unit_resumes && !unit_resume_length && !unit_sets && !unit_kills && !unit_clocks && !unit_opens && !unit_closes && !unit_sends && !unit_event_io.calls);
    }
}
static void unit_stale_early_slots(void) {
    for (unsigned first_exit = 0; first_exit < 2; first_exit++) {
        struct task *parent = unit_begin(); parent->pending = PROCESS; pending = reservations = 1; peak = 2;
        unit_event_old = 102; unit_info.op = PTRACE_SYSCALL_INFO_EXIT; unit_info.exit.is_error = 0;
        early_exit_seen[0] = 1; // A free slot must receive the new stop's own state.
        if (!first_exit) unit_hold_wait(0, SIGSTOP, 0);
        unit_hold_wait(PTRACE_EVENT_EXIT, SIGTRAP, 1);
        struct user_regs_struct regs = unit_regs;
        unit_wait_vector[0] = (struct unit_wait){ .tid = root, .status = (PTRACE_EVENT_FORK << 16) | (SIGTRAP << 8) | 0x7f };
        unit_wait_vector[1] = (struct unit_wait){ .tid = 0 }; unit_wait_length = 2; unit_wait_index = 0; errno = EDOM;
        assert(reap() == 0 && errno == EDOM && !first_seen && !failed && !cancelled && !root_terminal && !closure_echild);
        assert(parent->born && parent->pending == PROCESS && task(102) && task(102)->tgid == 102 && !task(102)->newborn && group(102));
        assert(live == 2 && births == 2 && peak == 2 && !pending && reservations == 1 && !waits && !failed_creations && !thread_births && !exec_retired);
        assert(unit_waits == (first_exit ? 4U : 6U) && unit_ptraces == 3 && unit_resumes == 2 && unit_resume_length == 2 && unit_opens == 1);
        assert(unit_resume_vector[0].tid == 102 && unit_resume_vector[0].request == PTRACE_CONT && !unit_resume_vector[0].forwarded);
        assert(unit_resume_vector[1].tid == root && unit_resume_vector[1].request == PTRACE_SYSCALL && !unit_resume_vector[1].forwarded);
        for (unsigned i = 0; i < TASKS; i++) assert(!early_stops[i] && !early_exit_seen[i]);
        assert(!unit_sets && !unit_kills && !unit_clocks && !unit_closes && !unit_sends && !unit_event_io.calls && !denied && !clone3_fallbacks);
        assert(!io_uring_setup_attempts && !io_uring_setup_eperm_writes && !eperm_register_writes && !eagain_register_writes && !memcmp(&unit_regs, &regs, sizeof(regs)));
    }
}
static void unit_input_contract(void) {
    for (unsigned i = 0; i < 2; i++) {
        unit_begin(); input_bytes = 1; unit_source_io = (struct unit_io){ .result = -1, .error = i ? EINTR : EAGAIN };
        input(); assert(!failed && !cancelled && !first_seen && unit_source_io.calls == 1 && !unit_input_io.calls && !unit_kills && !unit_clocks);
    }
    unit_begin(); input_bytes = 1; unit_source_io = (struct unit_io){ .result = -1, .error = EIO };
    input(); unit_site(N_INPUT_READ); assert(failed && cancelled && unit_kills == 1 && unit_closes == 2 && input_settled && !written_bytes && errno == EIO);
    unit_begin(); unit_source_io = (struct unit_io){ .bytes = "x", .length = 1, .result = 1 };
    input(); unit_site(N_INPUT_EOF); assert(failed && cancelled && unit_kills == 1 && input_settled && !written_bytes);
    for (unsigned i = 0; i < 2; i++) {
        unit_begin(); input_bytes = 1; unit_source_io = (struct unit_io){ .bytes = "x", .length = 1, .result = 1 };
        unit_input_io = (struct unit_io){ .result = -1, .error = i ? EINTR : EAGAIN };
        input(); assert(!failed && !cancelled && !first_seen && !written_bytes && unit_input_io.calls == 1 && !unit_kills);
        unit_input_io.result = 1; unit_source_io.result = 0;
        input(); assert(input_settled && source_eof && written_bytes == 1 && !first_seen && !unit_kills && !unit_clocks);
    }
    unit_begin(); input_bytes = 1; unit_source_io = (struct unit_io){ .bytes = "x", .length = 1, .result = 1 };
    unit_input_io = (struct unit_io){ .result = -1, .error = EIO };
    input(); unit_site(N_INPUT_WRITE); assert(failed && cancelled && unit_kills == 1 && input_settled && !written_bytes && errno == EIO);
}
static void unit_owner_cancel_context(void) {
    const int sites[] = { N_OWNER_CANCEL, N_OWNER_CANCEL_QUEUED_STOP, N_OWNER_CANCEL_QUEUED_EXIT, N_OWNER_CANCEL_QUEUED_MIXED };
    _Static_assert(N_OWNER_CANCEL == 8 && N_OWNER_CANCEL_QUEUED_STOP == 54 && N_OWNER_CANCEL_QUEUED_MIXED == 56, "stable owner-cancel diagnostics");
    for (unsigned kind = 0; kind < 4; kind++) {
        struct task *parent = unit_begin(); parent->pending = PROCESS; pending = reservations = 1; peak = 2;
        early_exit_seen[0] = early_exit_seen[TASKS / 2] = 1; // Free-slot bits are not recorded queue entries.
        if (kind) { early_stops[TASKS - 1] = 102; early_exit_seen[TASKS - 1] = kind == 2; }
        if (kind == 3) { early_stops[1] = 103; early_exit_seen[1] = 1; }
        struct task before_tasks[TASKS]; struct group before_groups[GROUPS]; struct user_regs_struct regs = unit_regs;
        pid_t before_early[TASKS]; unsigned char before_exit_seen[TASKS];
        memcpy(before_tasks, tasks, sizeof(tasks)); memcpy(before_groups, groups, sizeof(groups));
        memcpy(before_early, early_stops, sizeof(early_stops)); memcpy(before_exit_seen, early_exit_seen, sizeof(early_exit_seen));
        errno = EDOM; assert(owner_cancel_site() == sites[kind] && errno == EDOM && !first_seen && !failed && !cancelled);
        assert(!unit_ptraces && !unit_waits && !unit_kills && !unit_clocks && !unit_opens && !unit_closes && !unit_sends && !unit_event_io.calls);
        unit_command_io = (struct unit_io){ .bytes = "CANCEL\n", .length = 7, .result = 7 };
        assert(commands() == 0 && errno == EDOM && failed && cancelled && command_count == 1 && unit_command_io.calls == 1 && unit_kills == 1); unit_site(sites[kind]);
        assert(!memcmp(tasks, before_tasks, sizeof(tasks)) && !memcmp(groups, before_groups, sizeof(groups)) && !memcmp(&unit_regs, &regs, sizeof(regs)));
        assert(!memcmp(early_stops, before_early, sizeof(early_stops)) && !memcmp(early_exit_seen, before_exit_seen, sizeof(early_exit_seen)));
        assert(live == 1 && births == 1 && pending == 1 && reservations == 1 && peak == 2 && !waits && !failed_creations && !thread_births && !exec_retired);
        if (!kind) {
            // NONE records the mark point only: a kernel wait may be reaped later.
            unit_wait_vector[0] = (struct unit_wait){ .tid = 102, .status = (PTRACE_EVENT_EXIT << 16) | (SIGTRAP << 8) | 0x7f };
            unit_wait_vector[1] = (struct unit_wait){ .tid = 0 }; unit_wait_length = 2;
            assert(reap() == 0 && errno == EDOM && early_stops[0] == 102 && early_exit_seen[0] == 1 && !task(102));
            assert(owner_cancel_site() == N_OWNER_CANCEL_QUEUED_EXIT && first_site == N_OWNER_CANCEL);
            assert(commands() == 0 && errno == EDOM && command_count == 2 && unit_command_io.calls == 2 && unit_kills == 2); unit_site(N_OWNER_CANCEL);
        }
        memcpy(before_early, early_stops, sizeof(early_stops)); memcpy(before_exit_seen, early_exit_seen, sizeof(early_exit_seen));
        unit_wait_vector[0] = (struct unit_wait){ .tid = root, .status = SIGKILL };
        unit_wait_vector[1] = (struct unit_wait){ .tid = -1, .error = ECHILD }; unit_wait_length = 2; unit_wait_index = 0;
        assert(reap() == -1 && errno == ECHILD && !closure_echild && root_terminal && failed && cancelled); unit_site(sites[kind]);
        char expected[80]; snprintf(expected, sizeof(expected), "EXITED 101 -1 9 1 %d\n", sites[kind]);
        assert(!strcmp(unit_events, expected) && !strstr(unit_events, "CLOSED") && unit_event_io.calls == 1);
        assert(!memcmp(early_stops, before_early, sizeof(early_stops)) && !memcmp(early_exit_seen, before_exit_seen, sizeof(early_exit_seen)));
        assert(!live && !pending && births == 1 && reservations == 1 && peak == 2 && waits == 1 && failed_creations == 1 && !thread_births && !exec_retired);
        assert(!task(root) && !task(102) && !task(103) && !group(root) && unit_closes == 1 && unit_waits == (kind ? 2U : 4U));
        assert(!unit_ptraces && !unit_resumes && !unit_sets && !unit_clocks && !unit_opens && !unit_sends && !denied && !clone3_fallbacks);
        assert(unit_kills == (kind ? 1U : 2U) && !io_uring_setup_attempts && !io_uring_setup_eperm_writes && !eperm_register_writes && !eagain_register_writes);
    }
    for (unsigned prior = 0; prior < 2; prior++) {
        struct task *parent = unit_begin(); struct task before = *parent; struct group held = groups[0]; struct user_regs_struct regs = unit_regs;
        early_stops[0] = 102; early_stops[TASKS - 1] = 103; early_exit_seen[TASKS - 1] = 1; early_exit_seen[1] = 1;
        int site = prior ? N_POLICY_DENIAL : N_UNKNOWN; assert(failure_mark(site) == -1);
        errno = EDOM; assert(owner_cancel_site() == N_OWNER_CANCEL_QUEUED_MIXED && errno == EDOM);
        unit_command_io = (struct unit_io){ .bytes = "CANCEL\n", .length = 7, .result = 7 };
        assert(commands() == 0 && errno == EDOM && failed && cancelled && command_count == 1 && unit_command_io.calls == 1 && unit_kills == 1); unit_site(site);
        assert(!memcmp(parent, &before, sizeof(before)) && !memcmp(&groups[0], &held, sizeof(held)) && !memcmp(&unit_regs, &regs, sizeof(regs)));
        assert(early_stops[0] == 102 && !early_exit_seen[0] && early_stops[TASKS - 1] == 103 && early_exit_seen[TASKS - 1] == 1 && !early_stops[1] && early_exit_seen[1] == 1);
        assert(live == 1 && births == 1 && peak == 1 && !pending && !reservations && !waits && !failed_creations && !thread_births && !exec_retired);
        assert(!unit_ptraces && !unit_resumes && !unit_sets && !unit_waits && !unit_clocks && !unit_opens && !unit_closes && !unit_sends && !unit_event_io.calls && !denied);
        assert(!clone3_fallbacks && !io_uring_setup_attempts && !io_uring_setup_eperm_writes && !eperm_register_writes && !eagain_register_writes);
    }
}
static void unit_commands_contract(void) {
    for (unsigned i = 0; i < 2; i++) {
        unit_begin(); unit_command_io = (struct unit_io){ .result = -1, .error = i ? EINTR : EAGAIN };
        assert(commands() == 0 && !first_seen && !failed && !cancelled && !owner_eof && !command_count && !unit_kills);
    }
    unit_begin(); shutting_down = 1; unit_command_io.result = 0;
    assert(commands() == 0 && !first_seen && owner_eof && !failed && !unit_kills);
    unit_begin(); unit_command_io.result = 0; unit_kill_error = EIO;
    assert(commands() == 0 && owner_eof && failed && cancelled && unit_kills == 1); unit_site(N_COMMAND_CHANNEL);
    unit_begin(); unit_command_io = (struct unit_io){ .bytes = "CANCEL\n", .length = 7, .result = 7 }; unit_kill_error = EIO;
    assert(commands() == 0 && command_count == 1 && failed && cancelled && unit_kills == 1); unit_site(N_OWNER_CANCEL);
    struct task *t = unit_begin(); launched = 0; unit_resume_failure = 1;
    unit_command_io = (struct unit_io){ .bytes = "LAUNCH\n", .length = 7, .result = 7 };
    assert(commands() == -1 && launched && !failed && !cancelled && unit_resumes == 1 && unit_ptraces == 1 && errno == EIO); unit_site(N_RESUME);
    // The failed LAUNCH remains buffered; consume it with an accepted shutdown.
    t = unit_begin(); launched = 0; closure_echild = 1;
    unit_command_io = (struct unit_io){ .bytes = "SHUTDOWN\n", .length = 9, .result = 9 };
    assert(commands() == 0 && shutting_down && launched && command_count == 2 && !first_seen);
    t = unit_begin(); memset(t, 0, sizeof(*t)); launched = 0;
    unit_command_io = (struct unit_io){ .bytes = "LAUNCH\n", .length = 7, .result = 7 };
    assert(commands() == -1 && launched && !unit_ptraces && !failed && !cancelled); unit_site(N_COMMAND_LAUNCH);
    t = unit_begin(); launched = 0; closure_echild = 1;
    unit_command_io = (struct unit_io){ .bytes = "SHUTDOWN\n", .length = 9, .result = 9 };
    assert(commands() == 0 && shutting_down && command_count == 2 && !first_seen && unit_resumes == 1 && !unit_clocks);
    unit_begin(); command_count = 64; unit_command_io = (struct unit_io){ .bytes = "CANCEL\n", .length = 7, .result = 7 };
    assert(commands() == -1 && command_count == 65 && !failed && !cancelled && !unit_kills); unit_site(N_COMMAND_LIMIT);
    unit_begin(); unit_command_io = (struct unit_io){ .bytes = "CANCEL\n", .length = 7, .result = 7 };
    assert(commands() == 0 && command_count == 2); unit_site(N_OWNER_CANCEL);
    unit_begin(); unit_command_io = (struct unit_io){ .bytes = "INVALID\n", .length = 8, .result = 8 };
    assert(commands() == -1 && command_count == 1 && !failed && !cancelled && !unit_kills); unit_site(N_COMMAND_PARSE);
    (void)t;
}
int main(void) {
    unit_latch_contract();
    struct task *t = unit_begin();
    assert(gate(t) == 0 && !failed && denied == 1 && io_uring_setup_attempts == 1 && !io_uring_setup_eperm_writes);
    assert(t->pending == URING_EPERM && unit_sets == 1 && unit_resumes == 1);
    unit_complete(t); assert(io_uring_setup_eperm_writes == 1 && eperm_register_writes == 1 && !failed);
    fallback_closure(); assert(!failed && !first_seen && unit_ptraces == 8 && !unit_clocks);

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
        unit_site(fault < 6 ? N_GATE_METADATA : fault == 6 ? N_SUPPRESS_REGS : N_RESUME);
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
        const int sites[] = { N_EXIT_METADATA, N_EXIT_METADATA, N_EXIT_METADATA, N_EXIT_METADATA,
            N_EXIT_URING_ACCOUNTING, N_EXIT_GETREGS, N_EXIT_SETREGS, N_EXIT_URING_ACCOUNTING, N_RESUME };
        unit_site(sites[fault]);
        assert(io_uring_setup_eperm_writes == (fault == 8 ? 1U : 0U));
        fallback_closure(); if (fault != 8) assert(failed);
    }
    t = unit_begin(); assert(gate(t) == 0); assert(terminal(t, 0) == 0 && failed && io_uring_setup_eperm_writes == 0); unit_site(N_TERMINAL_ACCOUNTING);
    t = unit_begin(); assert(gate(t) == 0);
    struct task *thread = add_task(102, root, 0); assert(thread); unit_event_old = 102;
    assert(exec_event(root) == -1 && task(root)->pending == URING_EPERM);

    t = unit_begin(); failed = 1; assert(gate(t) == 0); unit_complete(t); fallback_closure(); assert(failed);
    t = unit_begin(); cancelled = 1; assert(gate(t) == 0); unit_complete(t); fallback_closure(); assert(cancelled);
    t = unit_begin(); denied = io_uring_setup_attempts = io_uring_setup_eperm_writes = INT32_MAX - 1U;
    assert(gate(t) == 0); unit_complete(t); assert(denied == INT32_MAX && io_uring_setup_eperm_writes == INT32_MAX);
    unit_info.op = PTRACE_SYSCALL_INFO_SECCOMP; unit_info.seccomp.nr = unit_regs.orig_rax = SYS_io_uring_setup;
    assert(gate(t) == -1 && failed && denied == INT32_MAX && unit_sets == 2);
    unit_site(N_COUNTER_BOUND);
    t = unit_begin(); denied = INT32_MAX; assert(gate(t) == -1 && failed && io_uring_setup_attempts == 0 && unit_sets == 0);
    t = unit_begin(); io_uring_setup_attempts = INT32_MAX; assert(gate(t) == -1 && failed && denied == 0 && unit_sets == 0);
    t = unit_begin(); assert(gate(t) == 0); io_uring_setup_eperm_writes = INT32_MAX; unit_info.op = PTRACE_SYSCALL_INFO_EXIT;
    assert(syscall_exit(t) == -1 && failed && unit_sets == 1 && io_uring_setup_eperm_writes == INT32_MAX);
    t = unit_begin(); io_uring_setup_attempts = 1; fallback_closure(); assert(failed);
    t = unit_begin(); io_uring_setup_attempts = io_uring_setup_eperm_writes = 1; fallback_closure(); assert(failed);

    t = unit_begin(); unit_info.seccomp.nr = unit_regs.orig_rax = SYS_ptrace;
    assert(gate(t) == 0 && failed && denied == 1 && t->pending == REJECT && io_uring_setup_attempts == 0);
    unit_site(N_POLICY_DENIAL);
    unit_info.op = PTRACE_SYSCALL_INFO_EXIT; assert(syscall_exit(t) == 0 && eperm_register_writes == 1 && !io_uring_setup_eperm_writes);
    t = unit_begin(); denied = INT32_MAX; unit_info.seccomp.nr = unit_regs.orig_rax = SYS_ptrace;
    assert(gate(t) == -1 && failed && denied == INT32_MAX && !unit_sets && !unit_resumes);
    unit_site(N_POLICY_DENIAL); // The earlier policy failure wins over secondary counter saturation.
    t = unit_begin(); live = 8; unit_info.seccomp.nr = unit_regs.orig_rax = SYS_fork;
    assert(gate(t) == 0 && failed && denied == 1 && t->reject_errno == EAGAIN);
    unit_site(N_POLICY_DENIAL);
    unit_info.op = PTRACE_SYSCALL_INFO_EXIT; assert(syscall_exit(t) == 0 && eagain_register_writes == 1 && !io_uring_setup_attempts);
    t = unit_begin(); unit_info.seccomp.nr = unit_regs.orig_rax = SYS_clone3;
    assert(gate(t) == 0 && !failed && t->pending == CLONE3 && !denied && clone3_fallbacks == 1);
    unit_info.op = PTRACE_SYSCALL_INFO_EXIT; assert(syscall_exit(t) == 0 && unit_regs.rax == (unsigned long long)-(long long)ENOSYS);
    assert(!first_seen && !failed && unit_ptraces == 8 && !unit_clocks);

    t = unit_begin(); assert(gate(t) == 0); unit_info.op = PTRACE_SYSCALL_INFO_EXIT; unit_set_failure = 2;
    unit_wait_tid = root; unit_wait_status = ((SIGTRAP | 0x80) << 8) | 0x7f;
    assert(reap() == -1 && !io_uring_setup_eperm_writes); failed = 1; cancel();
    assert(failed && cancelled && unit_kills == 1);
    unit_site(N_EXIT_SETREGS);
    unit_wait_and_group_contract(); unit_birth_wait_vectors(); unit_early_rejection_vectors(); unit_held_exit_success(); unit_held_exit_rejections(); unit_stale_early_slots(); unit_input_contract(); unit_owner_cancel_context(); unit_commands_contract();
    printf("IO_URING_FALLBACK_UNIT_OK %u\n", unit_cases);
    return 0;
}
