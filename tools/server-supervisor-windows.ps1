# Source-only draft for tablo/server-observer-windows-interface/v1.
# Runtime admission belongs to the containing Node lease. Never dot-source this file.
param([string] $PlanFile, [string] $PlanSha256)

$ErrorActionPreference = 'Stop'
$ProgressPreference = 'SilentlyContinue'
$adapterEntryTicks = [Diagnostics.Stopwatch]::GetTimestamp()
$adapterSource = @'
using System;
using System.Collections.Generic;
using System.Collections.Concurrent;
using System.Diagnostics;
using System.Globalization;
using System.IO;
using System.Linq;
using System.Runtime.InteropServices;
using System.Security.AccessControl;
using System.Security.Cryptography;
using System.Security.Principal;
using System.Text;
using System.Text.Json;
using System.Text.RegularExpressions;
using System.Threading;
using Microsoft.Win32.SafeHandles;

namespace Tablo.I39.WindowsAdapter
{
    // All exception text is private. Only the finite codes below cross the boundary.
    internal sealed class Refusal : Exception
    {
        internal readonly string Code;
        internal Refusal(string code) { Code = code; }
    }

    internal static class N
    {
        internal const uint KillOnClose = 0x2000, ActiveLimit = 8;
        internal const uint Suspended = 4, UnicodeEnvironment = 0x400;
        internal const uint ExtendedStartup = 0x80000, NoWindow = 0x08000000;
        internal const uint Timeout = 258, Signaled = 0;
        internal static readonly IntPtr Invalid = new IntPtr(-1);

        [StructLayout(LayoutKind.Sequential)] internal struct FT { internal uint Low, High; }
        [StructLayout(LayoutKind.Sequential)] internal struct SA
        { internal int Size; internal IntPtr Security; internal int Inherit; }
        [StructLayout(LayoutKind.Sequential, CharSet = CharSet.Unicode)] internal struct SI
        {
            internal int Size;
            internal IntPtr Reserved, Desktop, Title;
            internal uint X, Y, XSize, YSize, XChars, YChars, Fill, Flags;
            internal ushort Show, Reserved2;
            internal IntPtr ReservedBytes, Input, Output, Error;
        }
        [StructLayout(LayoutKind.Sequential)] internal struct SIX
        { internal SI Startup; internal IntPtr Attributes; }
        [StructLayout(LayoutKind.Sequential)] internal struct PI
        { internal IntPtr Process, Thread; internal uint Pid, Tid; }
        [StructLayout(LayoutKind.Sequential)] internal struct BasicLimits
        {
            internal long ProcessTime, JobTime;
            internal uint Flags;
            internal UIntPtr MinWorkingSet, MaxWorkingSet;
            internal uint ActiveProcesses;
            internal UIntPtr Affinity;
            internal uint Priority, Scheduling;
        }
        [StructLayout(LayoutKind.Sequential)] internal struct IO
        { internal ulong ReadOps, WriteOps, OtherOps, ReadBytes, WriteBytes, OtherBytes; }
        [StructLayout(LayoutKind.Sequential)] internal struct Limits
        {
            internal BasicLimits Basic;
            internal IO Io;
            internal UIntPtr ProcessMemory, JobMemory, PeakProcessMemory, PeakJobMemory;
        }
        [StructLayout(LayoutKind.Sequential)] internal struct Accounting
        {
            internal long User, Kernel, PeriodUser, PeriodKernel;
            internal uint Faults, Total, Active, Terminated;
        }
        [StructLayout(LayoutKind.Sequential)] internal struct FileInfo
        {
            internal uint Attributes;
            internal FT Creation, Access, Write;
            internal uint Volume, SizeHigh, SizeLow, Links, IndexHigh, IndexLow;
        }

        [DllImport("kernel32.dll", CharSet = CharSet.Unicode, SetLastError = true)]
        internal static extern IntPtr CreateJobObjectW(IntPtr attributes, string name);
        [DllImport("kernel32.dll", SetLastError = true)]
        [return: MarshalAs(UnmanagedType.Bool)] internal static extern bool SetInformationJobObject(IntPtr job, int type, ref Limits info, uint size);
        [DllImport("kernel32.dll", SetLastError = true)]
        [return: MarshalAs(UnmanagedType.Bool)] internal static extern bool QueryInformationJobObject(IntPtr job, int type, out Accounting info, uint size, IntPtr returned);
        [DllImport("kernel32.dll", EntryPoint = "QueryInformationJobObject", SetLastError = true)]
        [return: MarshalAs(UnmanagedType.Bool)] internal static extern bool QueryJobLimits(IntPtr job, int type, out Limits info, uint size, IntPtr returned);
        [DllImport("kernel32.dll", SetLastError = true)]
        [return: MarshalAs(UnmanagedType.Bool)] internal static extern bool TerminateJobObject(IntPtr job, uint code);
        [DllImport("kernel32.dll", SetLastError = true)]
        [return: MarshalAs(UnmanagedType.Bool)] internal static extern bool CloseHandle(IntPtr handle);
        [DllImport("kernel32.dll")] internal static extern IntPtr GetCurrentProcess();
        [DllImport("kernel32.dll")] internal static extern uint GetCurrentProcessId();
        [DllImport("kernel32.dll", SetLastError = true)]
        [return: MarshalAs(UnmanagedType.Bool)] internal static extern bool GetProcessTimes(IntPtr process, out FT creation, out FT exit, out FT kernel, out FT user);
        [DllImport("kernel32.dll", CharSet = CharSet.Unicode, SetLastError = true)]
        [return: MarshalAs(UnmanagedType.Bool)] internal static extern bool QueryFullProcessImageNameW(IntPtr process, uint flags, StringBuilder name, ref uint size);
        [DllImport("kernel32.dll", SetLastError = true)] internal static extern uint WaitForSingleObject(IntPtr handle, uint milliseconds);
        [DllImport("kernel32.dll", SetLastError = true)]
        [return: MarshalAs(UnmanagedType.Bool)] internal static extern bool GetExitCodeProcess(IntPtr process, out uint code);
        [DllImport("kernel32.dll", SetLastError = true)] internal static extern uint ResumeThread(IntPtr thread);
        [DllImport("kernel32.dll", SetLastError = true)]
        [return: MarshalAs(UnmanagedType.Bool)] internal static extern bool InitializeProcThreadAttributeList(IntPtr list, int count, uint flags, ref UIntPtr size);
        [DllImport("kernel32.dll", SetLastError = true)]
        [return: MarshalAs(UnmanagedType.Bool)] internal static extern bool UpdateProcThreadAttribute(IntPtr list, uint flags, UIntPtr attribute, IntPtr value, UIntPtr size, IntPtr previous, IntPtr returned);
        [DllImport("kernel32.dll")] internal static extern void DeleteProcThreadAttributeList(IntPtr list);
        [DllImport("kernel32.dll", CharSet = CharSet.Unicode, SetLastError = true)]
        [return: MarshalAs(UnmanagedType.Bool)] internal static extern bool CreateProcessW(string executable, StringBuilder command, IntPtr processSecurity, IntPtr threadSecurity, [MarshalAs(UnmanagedType.Bool)] bool inherit, uint flags, IntPtr environment, string cwd, ref SIX startup, out PI process);
        [DllImport("kernel32.dll", SetLastError = true)]
        [return: MarshalAs(UnmanagedType.Bool)] internal static extern bool CreatePipe(out IntPtr read, out IntPtr write, ref SA attributes, uint size);
        [DllImport("kernel32.dll", SetLastError = true)]
        [return: MarshalAs(UnmanagedType.Bool)] internal static extern bool SetHandleInformation(IntPtr handle, uint mask, uint flags);
        [DllImport("kernel32.dll", SetLastError = true)]
        [return: MarshalAs(UnmanagedType.Bool)] internal static extern bool DuplicateHandle(IntPtr sourceProcess, SafeFileHandle source, IntPtr targetProcess, out IntPtr target, uint access, [MarshalAs(UnmanagedType.Bool)] bool inherit, uint options);
        [DllImport("kernel32.dll", SetLastError = true)]
        [return: MarshalAs(UnmanagedType.Bool)] internal static extern bool WriteFile(IntPtr handle, byte[] bytes, uint count, out uint written, IntPtr overlapped);
        [DllImport("kernel32.dll", CharSet = CharSet.Unicode, SetLastError = true)]
        internal static extern SafeFileHandle CreateFileW(string name, uint access, uint share, IntPtr security, uint creation, uint flags, IntPtr template);
        [DllImport("kernel32.dll", SetLastError = true)]
        [return: MarshalAs(UnmanagedType.Bool)] internal static extern bool GetFileInformationByHandle(SafeFileHandle handle, out FileInfo info);
        [DllImport("kernel32.dll", CharSet = CharSet.Unicode, SetLastError = true)]
        internal static extern uint GetFinalPathNameByHandleW(SafeFileHandle handle, StringBuilder path, uint count, uint flags);
    }

    public sealed class Controller
    {
        const int FrameLimit = 65536, PayloadLimit = 32768, FrameCount = 64;
        const string Domain = "tablo-i39-windows-adapter/1\n";
        static readonly UTF8Encoding Utf8 = new UTF8Encoding(false, true);
        static readonly JsonDocumentOptions JsonOptions = new JsonDocumentOptions { MaxDepth = 16 };
        readonly object stateGate = new object(), jobGate = new object();
        readonly BlockingCollection<byte[]> output = new BlockingCollection<byte[]>(FrameCount);
        readonly AutoResetEvent launchWake = new AutoResetEvent(false);
        readonly AutoResetEvent cancelWake = new AutoResetEvent(false);
        readonly List<IDisposable> pins = new List<IDisposable>();
        readonly HashSet<string> directories = new HashSet<string>(StringComparer.OrdinalIgnoreCase);
        readonly long entryTicks;
        JsonDocument planDocument;
        JsonElement plan, launch;
        byte[] key, inputBytes;
        string nonce, planId, root, planHash, jobId, executable, executableHash, cwd, outPath, errPath, inputMode;
        string creation, actualImage;
        long budgetNs, sendSeq, receiveSeq;
        IntPtr job, process, thread, attributes, jobList, handleList, environment;
        IntPtr childInput, childOutput, childError;
        IntPtr inputWrite;
        FileStream outFile, errFile;
        uint controllerPid;
        uint configuredActiveLimit;
        readonly uint[] activeObservations = new uint[64];
        int nextActiveObservation;
        // A sampled maximum is not a measured native peak and is never emitted as one.
        uint largestObservedActive;
        N.Accounting lastAccounting;
        int launchState, cancelled, shutdown, finished, failed, writerFailed, writerSettled, lifecycleSettled;
        int inputSettled, inputClosed, rootExited, closureKnown, threadClosed;
        int terminationRequested, terminationAccepted;
        int result = 1;
        string cancelReason = "shutdown";
        bool attributeInitialized;

        Controller(long entry) { entryTicks = entry; }
        static void Require(bool value, string code) { if (!value) throw new Refusal(code); }
        static void Native(bool value) { Require(value, "NATIVE_FAILURE"); }
        static Dictionary<string, object> Obj(params object[] items)
        {
            var result = new Dictionary<string, object>(StringComparer.Ordinal);
            for (int i = 0; i < items.Length; i += 2) result.Add((string)items[i], items[i + 1]);
            return result;
        }
        static string Str(JsonElement value)
        { Require(value.ValueKind == JsonValueKind.String, "SCHEMA_INVALID"); return value.GetString(); }
        static long Int(JsonElement value)
        {
            Require(value.ValueKind == JsonValueKind.Number && Regex.IsMatch(value.GetRawText(), "^(0|[1-9][0-9]*)$"), "SCHEMA_INVALID");
            long number; Require(value.TryGetInt64(out number), "SCHEMA_INVALID"); return number;
        }
        static void Fields(JsonElement value, params string[] names)
        {
            Require(value.ValueKind == JsonValueKind.Object, "SCHEMA_INVALID");
            var expected = new HashSet<string>(names, StringComparer.Ordinal);
            foreach (var property in value.EnumerateObject()) Require(expected.Remove(property.Name), "SCHEMA_INVALID");
            Require(expected.Count == 0, "SCHEMA_INVALID");
        }
        static void Unique(JsonElement value)
        {
            if (value.ValueKind == JsonValueKind.Object)
            {
                var seen = new HashSet<string>(StringComparer.Ordinal);
                foreach (var p in value.EnumerateObject()) { Require(seen.Add(p.Name), "SCHEMA_INVALID"); Unique(p.Value); }
            }
            else if (value.ValueKind == JsonValueKind.Array) foreach (var item in value.EnumerateArray()) Unique(item);
        }
        static JsonDocument Parse(byte[] bytes)
        {
            Require(bytes.Length > 0 && !(bytes.Length >= 3 && bytes[0] == 239 && bytes[1] == 187 && bytes[2] == 191), "SCHEMA_INVALID");
            Utf8.GetString(bytes); // Strict UTF-8 validation; JsonDocument alone replaces some invalid strings.
            var doc = JsonDocument.Parse(bytes, JsonOptions);
            try { Require(doc.RootElement.ValueKind == JsonValueKind.Object, "SCHEMA_INVALID"); Unique(doc.RootElement); return doc; }
            catch { doc.Dispose(); throw; }
        }
        static bool Same(JsonElement left, JsonElement right)
        {
            if (left.ValueKind != right.ValueKind) return false;
            if (left.ValueKind == JsonValueKind.Object)
            {
                var a = left.EnumerateObject().ToArray(); var b = right.EnumerateObject().ToArray();
                if (a.Length != b.Length) return false;
                foreach (var p in a) { JsonElement other; if (!right.TryGetProperty(p.Name, out other) || !Same(p.Value, other)) return false; }
                return true;
            }
            if (left.ValueKind == JsonValueKind.Array)
            {
                var a = left.EnumerateArray().ToArray(); var b = right.EnumerateArray().ToArray();
                return a.Length == b.Length && a.Zip(b, Same).All(x => x);
            }
            return left.ValueKind == JsonValueKind.String ? left.GetString() == right.GetString() : left.GetRawText() == right.GetRawText();
        }
        static byte[] Base64(string text, int maximum)
        {
            Require(text.Length <= ((maximum + 2) / 3) * 4, "BUFFER_LIMIT");
            byte[] bytes = Convert.FromBase64String(text);
            Require(bytes.Length <= maximum && Convert.ToBase64String(bytes) == text, "SCHEMA_INVALID"); return bytes;
        }
        static string Hex(byte[] bytes) { return Convert.ToHexString(bytes).ToLowerInvariant(); }
        static string Hash(byte[] bytes) { using (var h = SHA256.Create()) return Hex(h.ComputeHash(bytes)); }
        static string Hash(FileStream file)
        { file.Position = 0; using (var h = SHA256.Create()) return Hex(h.ComputeHash(file)); }
        static string Digest(JsonElement value)
        { string s = Str(value); Require(Regex.IsMatch(s, "^[0-9a-f]{64}$"), "SCHEMA_INVALID"); return s; }
        static string PathName(string value)
        {
            Require(value != null && value.Length >= 3 && value.Length <= 32760 && value.IndexOf('\0') < 0, "PATH_INVALID");
            string path = value.Replace('/', '\\');
            Require(Regex.IsMatch(path, "^[A-Za-z]:\\\\") && !path.Substring(2).Contains(":"), "PATH_INVALID");
            Require(string.Equals(Path.GetFullPath(path), path, StringComparison.OrdinalIgnoreCase), "PATH_INVALID");
            foreach (string part in path.Substring(3).Split('\\'))
            {
                Require(part.Length > 0 && !part.EndsWith(".") && !part.EndsWith(" ") && !part.Any(c => c < 32) && part.IndexOfAny(new[] { '<', '>', '"', '|', '?', '*' }) < 0, "PATH_INVALID");
                Require(!Regex.IsMatch(part, "^(CON|PRN|AUX|NUL|COM[1-9]|LPT[1-9])(?:\\.|$)", RegexOptions.IgnoreCase), "PATH_INVALID");
            }
            return path;
        }
        string Owned(JsonElement value, bool allowRoot = false)
        {
            string path = PathName(Str(value));
            Require((allowRoot && string.Equals(path, root, StringComparison.OrdinalIgnoreCase)) || path.StartsWith(root + "\\", StringComparison.OrdinalIgnoreCase), "PATH_INVALID"); return path;
        }
        void PinDirectories(string directory)
        {
            var chain = new Stack<string>();
            for (string current = directory; current != null; current = Path.GetDirectoryName(current)) chain.Push(current);
            while (chain.Count != 0)
            {
                string path = chain.Pop(); if (directories.Contains(path)) continue;
                var handle = N.CreateFileW(path, 0x80, 3, IntPtr.Zero, 3, 0x02200000, IntPtr.Zero);
                if (handle.IsInvalid) { handle.Dispose(); throw new Refusal("PATH_INVALID"); }
                N.FileInfo info;
                if (!N.GetFileInformationByHandle(handle, out info) || (info.Attributes & 0x400) != 0 || (info.Attributes & 0x10) == 0)
                { handle.Dispose(); throw new Refusal("PATH_INVALID"); }
                pins.Add(handle); directories.Add(path); // No FILE_SHARE_DELETE: pin every ancestor.
            }
        }
        void Regular(FileStream stream, string expected, bool singleLink)
        {
            N.FileInfo info;
            Native(N.GetFileInformationByHandle(stream.SafeFileHandle, out info));
            Require((info.Attributes & (0x400 | 0x10 | 0x40)) == 0 && (!singleLink || info.Links == 1), "PATH_INVALID");
            var path = new StringBuilder(32768);
            uint count = N.GetFinalPathNameByHandleW(stream.SafeFileHandle, path, (uint)path.Capacity, 0);
            Require(count > 0 && count < path.Capacity, "PATH_INVALID");
            Require(string.Equals(path.ToString(), "\\\\?\\" + expected, StringComparison.OrdinalIgnoreCase), "PATH_INVALID");
        }
        FileStream ReadPin(string path, bool singleLink = false)
        {
            PinDirectories(Path.GetDirectoryName(path));
            // Open the final component itself. Reject reparse/device/directory attributes
            // before FileStream construction, ACL lookup or any content read/hash.
            var handle = N.CreateFileW(path, 0x80000000, 1, IntPtr.Zero, 3, 0x08200000, IntPtr.Zero);
            if (handle.IsInvalid) { handle.Dispose(); throw new Refusal("PATH_INVALID"); }
            try
            {
                N.FileInfo info; Native(N.GetFileInformationByHandle(handle, out info));
                Require((info.Attributes & (0x400 | 0x10 | 0x40)) == 0, "PATH_INVALID");
                var stream = new FileStream(handle, FileAccess.Read, 65536, false);
                pins.Add(stream); Regular(stream, path, singleLink); return stream;
            }
            catch { handle.Dispose(); throw; }
        }
        static byte[] ReadBounded(FileStream stream, int maximum)
        {
            Require(stream.Length <= maximum, "BUFFER_LIMIT");
            var bytes = new byte[(int)stream.Length]; int offset = 0;
            while (offset < bytes.Length) { int count = stream.Read(bytes, offset, bytes.Length - offset); Require(count != 0, "FILE_CHANGED"); offset += count; }
            Require(stream.ReadByte() == -1, "FILE_CHANGED"); return bytes;
        }
        static bool PrivateOwner(SecurityIdentifier owner, SecurityIdentifier user, SecurityIdentifier tokenOwner)
        {
            if (owner == null || user == null) return false;
            if (owner.Equals(user)) return true;
            return tokenOwner != null && owner.Equals(tokenOwner) &&
                (owner.IsWellKnown(WellKnownSidType.LocalSystemSid) || owner.IsWellKnown(WellKnownSidType.BuiltinAdministratorsSid));
        }
        static void PrivateFile(string path)
        {
            using (var identity = WindowsIdentity.GetCurrent())
            {
                var security = FileSystemAclExtensions.GetAccessControl(new System.IO.FileInfo(path));
                var descriptor = new RawSecurityDescriptor(security.GetSecurityDescriptorBinaryForm(), 0);
                Require(descriptor.DiscretionaryAcl != null, "AUTHORITY_INVALID");
                Require(PrivateOwner((SecurityIdentifier)security.GetOwner(typeof(SecurityIdentifier)), identity.User, identity.Owner), "AUTHORITY_INVALID");
                var system = new SecurityIdentifier(WellKnownSidType.LocalSystemSid, null);
                var admins = new SecurityIdentifier(WellKnownSidType.BuiltinAdministratorsSid, null);
                foreach (FileSystemAccessRule rule in security.GetAccessRules(true, true, typeof(SecurityIdentifier)))
                    if (rule.AccessControlType == AccessControlType.Allow)
                        Require(rule.IdentityReference.Equals(identity.User) || rule.IdentityReference.Equals(system) || rule.IdentityReference.Equals(admins), "AUTHORITY_INVALID");
            }
        }
        string PinExecutable(JsonElement spec, string actual = null)
        {
            Fields(spec, "path", "sha256"); string path = PathName(Str(spec.GetProperty("path")));
            if (actual != null) Require(string.Equals(path, PathName(actual), StringComparison.OrdinalIgnoreCase), "PIN_MISMATCH");
            Require(Hash(ReadPin(path)) == Digest(spec.GetProperty("sha256")), "PIN_MISMATCH"); return path;
        }
        bool Expired()
        { return (Stopwatch.GetTimestamp() - entryTicks) * (1000000000.0 / Stopwatch.Frequency) >= budgetNs; }
        void TimeLeft() { Require(!Expired(), "BUDGET_EXHAUSTED"); }

        void Admit(string file, string digest, string source, string powershell)
        {
            Require(OperatingSystem.IsWindows() && IntPtr.Size == 8, "PLATFORM_UNSUPPORTED");
            Require(digest != null && Regex.IsMatch(digest, "^[0-9a-f]{64}$"), "PIN_MISMATCH");
            file = PathName(file); byte[] bytes = ReadBounded(ReadPin(file, true), 262144);
            PrivateFile(file);
            Require(Hash(bytes) == digest, "PIN_MISMATCH"); planHash = digest;
            planDocument = Parse(bytes); plan = planDocument.RootElement;
            Fields(plan, "schema", "nonce", "root", "planId", "authorityFile", "authorityLength", "adapter", "powershell", "parentNodePid", "launch", "job", "limits", "initialRemainingNanoseconds", "launchCanonicalBase64", "launchSha256");
            Require(Str(plan.GetProperty("schema")) == "tablo/server-observer-windows-plan/v1", "SCHEMA_INVALID");
            nonce = Str(plan.GetProperty("nonce")); Require(Regex.IsMatch(nonce, "^[0-9a-f]{32}$"), "SCHEMA_INVALID");
            planId = Str(plan.GetProperty("planId")); Require(planId == nonce + "/windows-controller/1", "SCHEMA_INVALID");
            string remaining = Str(plan.GetProperty("initialRemainingNanoseconds"));
            Require(Regex.IsMatch(remaining, "^[1-9][0-9]{0,12}$") && long.TryParse(remaining, out budgetNs) && budgetNs <= 1800000000000L, "SCHEMA_INVALID");
            TimeLeft(); root = PathName(Str(plan.GetProperty("root"))); PinDirectories(root);
            Require(Int(plan.GetProperty("parentNodePid")) > 0 && Int(plan.GetProperty("parentNodePid")) <= uint.MaxValue, "SCHEMA_INVALID");
            PinExecutable(plan.GetProperty("adapter"), source); PinExecutable(plan.GetProperty("powershell"), powershell);
            Require(Int(plan.GetProperty("authorityLength")) == 32, "SCHEMA_INVALID");
            string authority = Owned(plan.GetProperty("authorityFile")); var keyFile = ReadPin(authority, true);
            PrivateFile(authority); key = ReadBounded(keyFile, 32); Require(key.Length == 32, "AUTHORITY_INVALID");
            var limits = plan.GetProperty("limits");
            Fields(limits, "maximumFrameBytes", "maximumPayloadBytes", "maximumFrames", "maximumParentSeconds", "maximumControllers", "cancellationExitCode");
            Require(Int(limits.GetProperty("maximumFrameBytes")) == FrameLimit && Int(limits.GetProperty("maximumPayloadBytes")) == PayloadLimit && Int(limits.GetProperty("maximumFrames")) == FrameCount && Int(limits.GetProperty("maximumParentSeconds")) == 1800 && Int(limits.GetProperty("maximumControllers")) == 1 && Int(limits.GetProperty("cancellationExitCode")) == 124, "SCHEMA_INVALID");
            var jobSpec = plan.GetProperty("job");
            Fields(jobSpec, "name", "logicalId", "killOnClose", "breakaway", "atomicJobListRequired", "activeProcessLimit");
            jobId = Str(jobSpec.GetProperty("logicalId"));
            Require(jobId == nonce + "/job/1" && jobSpec.GetProperty("name").ValueKind == JsonValueKind.Null && jobSpec.GetProperty("killOnClose").ValueKind == JsonValueKind.True && jobSpec.GetProperty("breakaway").ValueKind == JsonValueKind.False && jobSpec.GetProperty("atomicJobListRequired").ValueKind == JsonValueKind.True, "SCHEMA_INVALID");
            long cap = Int(jobSpec.GetProperty("activeProcessLimit")); Require(cap >= 1 && cap <= 9, "SCHEMA_INVALID");
            launch = plan.GetProperty("launch");
            byte[] canonical = Base64(Str(plan.GetProperty("launchCanonicalBase64")), PayloadLimit);
            Require(Hash(canonical) == Digest(plan.GetProperty("launchSha256")), "PIN_MISMATCH");
            using (var original = Parse(canonical)) Require(Same(launch, original.RootElement), "PIN_MISMATCH");
            Fields(launch, "executable", "argv", "cwd", "environment", "stdout", "stderr", "input");
            executable = PinExecutable(launch.GetProperty("executable")); executableHash = Digest(launch.GetProperty("executable").GetProperty("sha256"));
            cwd = Owned(launch.GetProperty("cwd"), true); PinDirectories(cwd);
            outPath = Owned(launch.GetProperty("stdout")); errPath = Owned(launch.GetProperty("stderr"));
            var distinct = new HashSet<string>(StringComparer.OrdinalIgnoreCase) { file, authority, executable, PathName(source), PathName(powershell) };
            Require(distinct.Add(outPath) && distinct.Add(errPath), "PATH_INVALID");
            var args = launch.GetProperty("argv"); Require(args.ValueKind == JsonValueKind.Array && args.GetArrayLength() <= 4096, "SCHEMA_INVALID");
            foreach (var arg in args.EnumerateArray()) Require(Str(arg).IndexOf('\0') < 0, "SCHEMA_INVALID");
            string command = CommandLine(); Require(command.Length < 32767, "BUFFER_LIMIT");
            PrepareEnvironment(launch.GetProperty("environment"));
            var input = launch.GetProperty("input"); Fields(input, "mode", "path", "sha256", "bytes"); inputMode = Str(input.GetProperty("mode"));
            if (inputMode == "none")
            {
                Require(Int(input.GetProperty("bytes")) == 0 && input.GetProperty("path").ValueKind == JsonValueKind.Null && input.GetProperty("sha256").ValueKind == JsonValueKind.Null, "SCHEMA_INVALID");
                inputBytes = Array.Empty<byte>();
            }
            else
            {
                Require(inputMode == "synthetic-file", "SCHEMA_INVALID");
                long length = Int(input.GetProperty("bytes")); Require(length >= 1 && length <= 1048576, "BUFFER_LIMIT");
                string path = Owned(input.GetProperty("path")); Require(distinct.Add(path), "PATH_INVALID");
                var inputFile = ReadPin(path, true); PrivateFile(path); inputBytes = ReadBounded(inputFile, 1048576);
                Require(inputBytes.Length == length && Hash(inputBytes) == Digest(input.GetProperty("sha256")), "PIN_MISMATCH");
            }
            TimeLeft();
            outFile = NewOutput(outPath); errFile = NewOutput(errPath);
            job = N.CreateJobObjectW(IntPtr.Zero, null); Require(job != IntPtr.Zero, "NATIVE_FAILURE");
            var nativeLimits = new N.Limits(); nativeLimits.Basic.Flags = N.KillOnClose | N.ActiveLimit; nativeLimits.Basic.ActiveProcesses = (uint)cap;
            Native(N.SetInformationJobObject(job, 9, ref nativeLimits, (uint)Marshal.SizeOf<N.Limits>()));
            N.Limits readback;
            Native(N.QueryJobLimits(job, 9, out readback, (uint)Marshal.SizeOf<N.Limits>(), IntPtr.Zero));
            Require(readback.Basic.Flags == (N.KillOnClose | N.ActiveLimit) && readback.Basic.ActiveProcesses == cap, "JOB_LIMIT_MISMATCH");
            configuredActiveLimit = readback.Basic.ActiveProcesses;
            var sa = new N.SA { Size = Marshal.SizeOf<N.SA>(), Inherit = 1 };
            IntPtr write;
            Native(N.CreatePipe(out childInput, out write, ref sa, 4096)); inputWrite = write;
            Native(N.SetHandleInformation(write, 1, 0));
            Native(N.DuplicateHandle(N.GetCurrentProcess(), outFile.SafeFileHandle, N.GetCurrentProcess(), out childOutput, 0, true, 2));
            Native(N.DuplicateHandle(N.GetCurrentProcess(), errFile.SafeFileHandle, N.GetCurrentProcess(), out childError, 0, true, 2));
            UIntPtr size = UIntPtr.Zero;
            bool first = N.InitializeProcThreadAttributeList(IntPtr.Zero, 2, 0, ref size);
            Require(!first && Marshal.GetLastWin32Error() == 122 && size.ToUInt64() > 0 && size.ToUInt64() <= 65536, "ATOMIC_JOB_UNSUPPORTED");
            attributes = Marshal.AllocHGlobal((int)size.ToUInt64());
            Native(N.InitializeProcThreadAttributeList(attributes, 2, 0, ref size)); attributeInitialized = true;
            jobList = Marshal.AllocHGlobal(IntPtr.Size); Marshal.WriteIntPtr(jobList, job);
            handleList = Marshal.AllocHGlobal(3 * IntPtr.Size);
            Marshal.WriteIntPtr(handleList, 0, childInput); Marshal.WriteIntPtr(handleList, IntPtr.Size, childOutput); Marshal.WriteIntPtr(handleList, 2 * IntPtr.Size, childError);
            Require(N.UpdateProcThreadAttribute(attributes, 0, new UIntPtr(0x0002000d), jobList, new UIntPtr((uint)IntPtr.Size), IntPtr.Zero, IntPtr.Zero), "ATOMIC_JOB_UNSUPPORTED");
            Native(N.UpdateProcThreadAttribute(attributes, 0, new UIntPtr(0x00020002), handleList, new UIntPtr((uint)(3 * IntPtr.Size)), IntPtr.Zero, IntPtr.Zero));
            Require(Active() == 0, "NATIVE_FAILURE"); TimeLeft();
        }
        FileStream NewOutput(string path)
        {
            PinDirectories(Path.GetDirectoryName(path));
            var file = new FileStream(path, FileMode.CreateNew, FileAccess.ReadWrite, FileShare.Read, 65536, FileOptions.SequentialScan);
            try { Regular(file, path, true); return file; } catch { file.Dispose(); throw; }
        }
        void PrepareEnvironment(JsonElement env)
        {
            Require(env.ValueKind == JsonValueKind.Object, "SCHEMA_INVALID");
            var values = new SortedDictionary<string, string>(StringComparer.OrdinalIgnoreCase);
            foreach (var property in env.EnumerateObject())
            {
                Require(property.Name.Length > 0 && property.Name.IndexOfAny(new[] { '=', '\0' }) < 0 && !values.ContainsKey(property.Name), "SCHEMA_INVALID");
                string value = Str(property.Value); Require(value.IndexOf('\0') < 0, "SCHEMA_INVALID"); values.Add(property.Name, value);
            }
            // A complete replacement block, including the empty-environment double NUL.
            string block = string.Join("\0", values.Select(p => p.Key + "=" + p.Value)) + "\0\0";
            Require(block.Length <= 32767, "BUFFER_LIMIT"); environment = Marshal.StringToHGlobalUni(block);
        }
        static string Quote(string arg)
        {
            // CommandLineToArgvW / CRT backslash-before-quote convention, including empty argv.
            var result = new StringBuilder("\""); int slashes = 0;
            foreach (char c in arg)
            {
                if (c == '\\') { slashes++; continue; }
                if (c == '"') { result.Append('\\', slashes * 2 + 1).Append(c); slashes = 0; continue; }
                result.Append('\\', slashes).Append(c); slashes = 0;
            }
            return result.Append('\\', slashes * 2).Append('"').ToString();
        }
        string CommandLine()
        { return Quote(executable) + string.Concat(launch.GetProperty("argv").EnumerateArray().Select(x => " " + Quote(Str(x)))); }
        static string Creation(IntPtr handle)
        {
            N.FT created, exit, kernel, user; Native(N.GetProcessTimes(handle, out created, out exit, out kernel, out user));
            return (((ulong)created.High << 32) | created.Low).ToString(CultureInfo.InvariantCulture);
        }
        uint Active()
        {
            lock (jobGate)
            {
                Require(job != IntPtr.Zero, "NATIVE_FAILURE"); N.Accounting accounting;
                Native(N.QueryInformationJobObject(job, 1, out accounting, (uint)Marshal.SizeOf<N.Accounting>(), IntPtr.Zero));
                // Active counts associated objects, including a rejected association until
                // its terminated process and references are released. The read-back Job
                // limit enforces admission; this raw counter is never a runnable peak.
                lastAccounting = accounting;
                activeObservations[nextActiveObservation] = accounting.Active;
                nextActiveObservation = (nextActiveObservation + 1) % activeObservations.Length;
                largestObservedActive = Math.Max(largestObservedActive, accounting.Active); return accounting.Active;
            }
        }
        Dictionary<string, object> ClosedAccounting()
        {
            lock (jobGate)
            {
                Require(Active() == 0, "NATIVE_FAILURE");
                return Obj("configuredLimit", configuredActiveLimit, "maximumAssociatedObserved", largestObservedActive,
                    "finalAssociated", lastAccounting.Active, "totalProcesses", lastAccounting.Total,
                    "totalLimitTerminated", lastAccounting.Terminated);
            }
        }
        void StartThread(ThreadStart action, string name)
        { var worker = new Thread(action) { IsBackground = true, Name = name }; worker.Start(); }
        void Emit(string kind, Dictionary<string, object> payload)
        {
            lock (output)
            {
                Require(sendSeq < FrameCount, "BUFFER_LIMIT"); byte[] bytes = JsonSerializer.SerializeToUtf8Bytes(payload);
                Require(bytes.Length <= PayloadLimit, "BUFFER_LIMIT"); string body = Convert.ToBase64String(bytes); long seq = ++sendSeq;
                string mac;
                using (var h = new HMACSHA256(key)) mac = Hex(h.ComputeHash(Utf8.GetBytes(Domain + "adapter-to-node\n" + nonce + "\n" + seq.ToString(CultureInfo.InvariantCulture) + "\n" + kind + "\n" + body)));
                byte[] frame = JsonSerializer.SerializeToUtf8Bytes(Obj("v", 1, "nonce", nonce, "seq", seq, "kind", kind, "payload", body, "mac", mac));
                Require(frame.Length + 1 <= FrameLimit, "BUFFER_LIMIT");
                var line = new byte[frame.Length + 1]; Buffer.BlockCopy(frame, 0, line, 0, frame.Length); line[frame.Length] = 10;
                Require(output.TryAdd(line), "BUFFER_LIMIT");
            }
        }
        void WriteEvents()
        {
            try
            {
                var stream = Console.OpenStandardOutput();
                foreach (byte[] line in output.GetConsumingEnumerable()) { stream.Write(line, 0, line.Length); stream.Flush(); }
            }
            catch { Interlocked.Exchange(ref writerFailed, 1); RequestCancel("owner-loss"); }
            finally { Volatile.Write(ref writerSettled, 1); }
        }
        void Protocol(byte[] frame)
        {
            Require(frame.Length >= 2 && frame[0] == (byte)'{' && frame[frame.Length - 1] == (byte)'}', "PROTOCOL_INVALID");
            using (var envelope = Parse(frame))
            {
                var e = envelope.RootElement; Fields(e, "v", "nonce", "seq", "kind", "payload", "mac");
                Require(Int(e.GetProperty("v")) == 1 && Str(e.GetProperty("nonce")) == nonce, "PROTOCOL_INVALID");
                long seq = Int(e.GetProperty("seq")); Require(seq == receiveSeq + 1 && seq <= FrameCount, "PROTOCOL_INVALID");
                string kind = Str(e.GetProperty("kind")); Require(kind == "launch" || kind == "cancel" || kind == "shutdown", "PROTOCOL_INVALID");
                string body = Str(e.GetProperty("payload")), mac = Digest(e.GetProperty("mac")); byte[] bytes = Base64(body, PayloadLimit);
                byte[] expected;
                using (var h = new HMACSHA256(key)) expected = h.ComputeHash(Utf8.GetBytes(Domain + "node-to-adapter\n" + nonce + "\n" + seq.ToString(CultureInfo.InvariantCulture) + "\n" + kind + "\n" + body));
                Require(CryptographicOperations.FixedTimeEquals(expected, Convert.FromHexString(mac)), "PROTOCOL_INVALID");
                using (var content = Parse(bytes))
                {
                    var p = content.RootElement;
                    if (kind == "launch") Fields(p, "planId", "launchSha256");
                    else if (kind == "cancel") Fields(p, "planId", "reason");
                    else Fields(p, "planId");
                    Require(Str(p.GetProperty("planId")) == planId, "PROTOCOL_INVALID"); receiveSeq = seq;
                    if (kind == "launch")
                    {
                        Require(Str(p.GetProperty("launchSha256")) == Str(plan.GetProperty("launchSha256")), "PROTOCOL_INVALID");
                        lock (stateGate)
                        {
                            Require(launchState == 0 && Volatile.Read(ref cancelled) == 0 && Volatile.Read(ref shutdown) == 0, "PROTOCOL_INVALID");
                            TimeLeft(); launchState = 1; launchWake.Set();
                        }
                    }
                    else if (kind == "cancel")
                    {
                        string reason = Str(p.GetProperty("reason"));
                        Require(reason == "deadline" || reason == "owner-loss" || reason == "protocol" || reason == "shutdown", "PROTOCOL_INVALID"); RequestCancel(reason);
                    }
                    else
                    {
                        Interlocked.Exchange(ref shutdown, 1);
                        if (Volatile.Read(ref closureKnown) == 0) RequestCancel("shutdown");
                    }
                }
            }
        }
        void ReadCommands()
        {
            try
            {
                var stream = Console.OpenStandardInput(); var chunk = new byte[4096]; var frame = new byte[FrameLimit - 1]; int used = 0;
                while (Volatile.Read(ref finished) == 0)
                {
                    int count = stream.Read(chunk, 0, chunk.Length);
                    if (count == 0)
                    {
                        if (used != 0) Fail("protocol", "PROTOCOL_INVALID");
                        else RequestCancel("owner-loss");
                        return;
                    }
                    for (int i = 0; i < count; i++)
                    {
                        byte b = chunk[i]; Require(b != 13, "PROTOCOL_INVALID");
                        if (b == 10)
                        {
                            Require(used != 0, "PROTOCOL_INVALID"); var bytes = new byte[used]; Buffer.BlockCopy(frame, 0, bytes, 0, used); used = 0; Protocol(bytes);
                        }
                        else { Require(used < frame.Length, "BUFFER_LIMIT"); frame[used++] = b; }
                    }
                }
            }
            catch { Fail("protocol", "PROTOCOL_INVALID"); }
        }
        void RequestCancel(string reason)
        {
            lock (stateGate)
            {
                if (Volatile.Read(ref cancelled) == 0) cancelReason = reason;
                Volatile.Write(ref cancelled, 1); launchWake.Set(); cancelWake.Set();
            }
        }
        void CancelJob()
        {
            while (Volatile.Read(ref finished) == 0)
            {
                cancelWake.WaitOne();
                try
                {
                    lock (jobGate)
                    {
                        if (job != IntPtr.Zero)
                        {
                            Volatile.Write(ref terminationRequested, 1);
                            if (N.TerminateJobObject(job, 124)) Volatile.Write(ref terminationAccepted, 1);
                            else Fail("cancel", "NATIVE_FAILURE");
                        }
                    }
                }
                catch { Fail("cancel", "NATIVE_FAILURE"); }
            }
        }
        void Fail(string phase, string code)
        {
            if (Interlocked.CompareExchange(ref failed, 1, 0) == 0)
            {
                // The frozen bool cannot describe a CreateProcess call still in flight.
                // Omit that receipt rather than assert a false no-launch fact; Node retains UNKNOWN.
                bool creationUnknown = Volatile.Read(ref launchState) == 2 && Volatile.Read(ref controllerPid) == 0;
                try
                {
                    if (!creationUnknown)
                        Emit("failed", Obj("planId", planId, "phase", phase, "code", code, "controllerStarted", Volatile.Read(ref controllerPid) != 0, "rootExitObserved", Volatile.Read(ref rootExited) != 0, "closureKnown", Volatile.Read(ref closureKnown) != 0, "nestedPhpClosure", "UNKNOWN_UNLESS_ACTUAL_RECEIPT"));
                }
                catch { Interlocked.Exchange(ref writerFailed, 1); }
            }
            RequestCancel(code == "BUDGET_EXHAUSTED" ? "deadline" : "protocol");
        }
        void CloseRaw(ref IntPtr handle)
        {
            if (handle == IntPtr.Zero) return;
            Native(N.CloseHandle(handle)); handle = IntPtr.Zero;
        }
        void FreeLaunchResources()
        {
            CloseRaw(ref childInput); CloseRaw(ref childOutput); CloseRaw(ref childError);
            if (attributes != IntPtr.Zero)
            {
                if (attributeInitialized) N.DeleteProcThreadAttributeList(attributes);
                Marshal.FreeHGlobal(attributes); attributes = IntPtr.Zero;
            }
            if (jobList != IntPtr.Zero) { Marshal.FreeHGlobal(jobList); jobList = IntPtr.Zero; }
            if (handleList != IntPtr.Zero) { Marshal.FreeHGlobal(handleList); handleList = IntPtr.Zero; }
            if (environment != IntPtr.Zero) { Marshal.FreeHGlobal(environment); environment = IntPtr.Zero; }
        }
        void ClosePins()
        {
            foreach (var pin in pins) pin.Dispose();
            pins.Clear();
        }
        void WriteInput()
        {
            long written = 0; string code = null;
            try
            {
                var buffer = new byte[4096];
                while (written < inputBytes.Length)
                {
                    if (Volatile.Read(ref cancelled) != 0 || Expired()) { code = "INPUT_CANCELLED"; break; }
                    int count = Math.Min(buffer.Length, inputBytes.Length - (int)written); Buffer.BlockCopy(inputBytes, (int)written, buffer, 0, count);
                    uint actual; bool ok = N.WriteFile(inputWrite, buffer, (uint)count, out actual, IntPtr.Zero);
                    Require(actual <= count, "NATIVE_FAILURE"); written += actual;
                    if (!ok || actual != count) { code = "INPUT_WRITE_FAILED"; break; }
                }
                Array.Clear(buffer, 0, buffer.Length);
            }
            catch { code = "INPUT_WRITE_FAILED"; }
            finally
            {
                try { CloseRaw(ref inputWrite); Volatile.Write(ref inputClosed, 1); } catch { code = "INPUT_CLOSE_FAILED"; }
                Array.Clear(inputBytes, 0, inputBytes.Length);
                try
                {
                    Emit("input", Obj("planId", planId, "mode", inputMode, "declaredBytes", inputBytes.Length, "writtenBytes", written, "settled", true, "stdinClosed", Volatile.Read(ref inputClosed) != 0, "errorCode", code));
                    Volatile.Write(ref inputSettled, 1);
                    if (code != null) Fail("input", code);
                }
                catch { Fail("input", "INPUT_CLOSE_FAILED"); }
            }
        }
        Dictionary<string, object> SettleStream(ref FileStream stream, string path)
        {
            TimeLeft(); stream.Flush(true); long length = stream.Length; string hash = Hash(stream);
            Require(stream.Length == length, "FILE_CHANGED"); stream.Dispose(); stream = null;
            return Obj("path", path, "bytes", length, "sha256", hash, "closed", true);
        }
        void Lifecycle()
        {
            try
            {
                launchWake.WaitOne();
                bool create;
                lock (stateGate)
                {
                    create = cancelled == 0 && shutdown == 0 && launchState == 1;
                    TimeLeft(); launchState = create ? 2 : -1;
                    // This is the creation commitment. A precommit cancel prevents creation.
                    // Never hold the command-state lock across a native or filesystem call.
                }
                if (create)
                {
                    var startup = new N.SIX(); startup.Startup.Size = Marshal.SizeOf<N.SIX>();
                    startup.Startup.Flags = 0x100 | 1; startup.Startup.Show = 0;
                    startup.Startup.Input = childInput; startup.Startup.Output = childOutput; startup.Startup.Error = childError; startup.Attributes = attributes;
                    N.PI identity;
                    bool created = N.CreateProcessW(executable, new StringBuilder(CommandLine()), IntPtr.Zero, IntPtr.Zero, true, N.Suspended | N.UnicodeEnvironment | N.ExtendedStartup | N.NoWindow, environment, cwd, ref startup, out identity);
                    if (!created) Volatile.Write(ref launchState, -1);
                    Native(created);
                    process = identity.Process; thread = identity.Thread; controllerPid = identity.Pid;
                    Volatile.Write(ref launchState, 3);
                    // A cancellation during CreateProcess may have terminated an empty Job.
                    // Reapply to the same owned Job now that the suspended child is published.
                    if (Volatile.Read(ref cancelled) != 0) cancelWake.Set();
                }
                FreeLaunchResources();
                if (controllerPid == 0)
                {
                    CloseRaw(ref inputWrite); Volatile.Write(ref inputClosed, 1);
                    outFile.Dispose(); outFile = null; errFile.Dispose(); errFile = null;
                    Require(Active() == 0, "NATIVE_FAILURE"); lock (jobGate) CloseRaw(ref job);
                    ClosePins();
                    Volatile.Write(ref closureKnown, 1);
                    Emit("notStarted", Obj("planId", planId, "controllerStarted", false, "reason", cancelReason, "closed", true));
                    return;
                }
                Require(controllerPid > 0 && process != IntPtr.Zero && thread != IntPtr.Zero, "IDENTITY_MISMATCH");
                creation = Creation(process);
                var image = new StringBuilder(32768); uint size = (uint)image.Capacity;
                Native(N.QueryFullProcessImageNameW(process, 0, image, ref size)); actualImage = image.ToString();
                Require(string.Equals(PathName(actualImage), executable, StringComparison.OrdinalIgnoreCase), "IDENTITY_MISMATCH");
                // Executable's original locked handle remains pinned through custody settlement.
                uint active = Active(); Require(active >= 1 || Volatile.Read(ref cancelled) != 0, "IDENTITY_MISMATCH");
                bool resume;
                lock (stateGate)
                {
                    TimeLeft(); resume = cancelled == 0;
                    // Final resume commitment is serialized with cancellation. The syscall
                    // stays outside the lock; cancellation after this commitment still kills
                    // only the same private Job, and never substitutes for a native wait.
                }
                if (resume) Require(N.ResumeThread(thread) == 1, "NATIVE_FAILURE");
                CloseRaw(ref thread); Volatile.Write(ref threadClosed, 1);
                Emit("started", Obj("planId", planId, "controllerPid", controllerPid, "controllerCreationFileTime", creation, "actualImagePath", actualImage, "actualImageSha256", executableHash, "jobLogicalId", jobId, "assignedAtCreation", true, "jobActiveProcesses", active, "nativeProcessHandleRetained", true, "nativeThreadHandleClosed", true));
                StartThread(WriteInput, "tablo-input");
                while (true)
                {
                    uint wait = N.WaitForSingleObject(process, 20);
                    if (wait == N.Signaled) break;
                    Require(wait == N.Timeout, "NATIVE_FAILURE"); TimeLeft(); Active();
                }
                uint exit; Native(N.GetExitCodeProcess(process, out exit));
                Volatile.Write(ref rootExited, 1);
                Emit("exited", Obj("planId", planId, "controllerPid", controllerPid, "controllerCreationFileTime", creation, "rootExitObserved", true, "waitResult", "signaled", "windowsExitUnsigned", exit, "windowsExitSigned", unchecked((int)exit), "terminationRequested", Volatile.Read(ref terminationRequested) != 0, "terminationAccepted", Volatile.Read(ref terminationAccepted) != 0, "posixSignal", null));
                while (Active() != 0 || Volatile.Read(ref inputSettled) == 0) { TimeLeft(); Thread.Sleep(5); }
                Require(Volatile.Read(ref inputClosed) != 0, "INPUT_CLOSE_FAILED");
                var stdout = SettleStream(ref outFile, outPath); var stderr = SettleStream(ref errFile, errPath);
                CloseRaw(ref process);
                var jobAccounting = ClosedAccounting(); lock (jobGate) CloseRaw(ref job);
                ClosePins();
                Volatile.Write(ref closureKnown, 1);
                Emit("closed", Obj("planId", planId, "controllerPid", controllerPid, "controllerCreationFileTime", creation, "rootExitObserved", true, "nativeProcessHandleClosed", true, "nativeThreadHandleClosed", Volatile.Read(ref threadClosed) != 0, "jobActiveProcesses", 0, "jobHandleClosed", true, "inputSettled", true, "stdinClosed", true, "stdout", stdout, "stderr", stderr, "custodySettled", true, "descendantStatuses", "NOT_COLLECTED_BY_ADAPTER", "jobAccounting", jobAccounting));
                result = exit == 0 && Volatile.Read(ref cancelled) == 0 && Volatile.Read(ref failed) == 0 ? 0 : 1;
            }
            catch (Refusal refusal) { Fail("lifecycle", refusal.Code); }
            catch { Fail("lifecycle", "NATIVE_FAILURE"); }
            finally { Volatile.Write(ref lifecycleSettled, 1); }
        }

        int Execute(string file, string digest, string source, string powershell)
        {
            try
            {
                Admit(file, digest, source, powershell);
                StartThread(WriteEvents, "tablo-events");
                Emit("ready", Obj("planId", planId, "adapterPid", N.GetCurrentProcessId(), "adapterProcessCreationFileTime", Creation(N.GetCurrentProcess()), "platform", "Windows", "planSha256", planHash, "jobLogicalId", jobId, "atomicJobListSupported", true, "capabilityReady", true));
                StartThread(CancelJob, "tablo-cancel"); StartThread(Lifecycle, "tablo-lifecycle"); StartThread(ReadCommands, "tablo-commands");
                while (true)
                {
                    if (Expired()) { Fail("budget", "BUDGET_EXHAUSTED"); break; }
                    if (Volatile.Read(ref writerFailed) != 0) break;
                    if (Volatile.Read(ref lifecycleSettled) != 0 && (Volatile.Read(ref failed) != 0 || Volatile.Read(ref cancelled) != 0 || Volatile.Read(ref shutdown) != 0)) break;
                    Thread.Sleep(5);
                }
                // No new settlement reserve. The original remaining budget is the only bound.
                output.CompleteAdding();
                while (Volatile.Read(ref writerSettled) == 0 && !Expired() && Volatile.Read(ref writerFailed) == 0) Thread.Sleep(1);
                return !Expired() && Volatile.Read(ref writerSettled) != 0 && Volatile.Read(ref failed) == 0 && Volatile.Read(ref writerFailed) == 0 && Volatile.Read(ref closureKnown) != 0 && Volatile.Read(ref cancelled) == 0 ? result : 1;
            }
            catch (Refusal refusal)
            {
                try { Console.Error.WriteLine(refusal.Code); } catch { }
                return 1;
            }
            catch
            {
                try { Console.Error.WriteLine("ADMISSION_FAILED"); } catch { }
                return 1;
            }
            finally
            {
                Volatile.Write(ref finished, 1);
                // Process exit closes every exact native handle, including an in-flight syscall's
                // reference. Never wait here for blocked input/FS work or infer its settlement.
                // Kill-on-close is the last containment backstop, not a controller exit receipt.
            }
        }
        public static int Run(string file, string digest, string source, string powershell, long entry)
        { return new Controller(entry).Execute(file, digest, source, powershell); }
    }
}
'@

try {
    if ([string]::IsNullOrEmpty($PlanFile) -or $PlanSha256 -cnotmatch '^[0-9a-f]{64}$') {
        [Console]::Error.WriteLine('INVOCATION_INVALID')
        exit 1
    }
    # In-memory declarations only; no compiler executable, acquisition, or helper process.
    Add-Type -TypeDefinition $adapterSource -Language CSharp -IgnoreWarnings -ErrorAction Stop -WarningAction SilentlyContinue | Out-Null
    $adapterExit = [Tablo.I39.WindowsAdapter.Controller]::Run(
        $PlanFile, $PlanSha256, $PSCommandPath, [Environment]::ProcessPath, $adapterEntryTicks
    )
    # Explicit exit stops background managed workers and releases the unnamed Job on all paths.
    [Environment]::Exit($adapterExit)
} catch {
    [Console]::Error.WriteLine('ADAPTER_INITIALIZATION_FAILED')
    [Environment]::Exit(1)
}
