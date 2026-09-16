using System;
using System.Collections.Generic;
using System.Diagnostics;
using System.Drawing;
using System.IO;
using System.Management;
using System.Net.WebSockets;
using System.Reflection;
using System.Text;
using System.Threading;
using System.Threading.Tasks;
using System.Web.Script.Serialization;
using System.Windows.Forms;
using Microsoft.Win32;

[assembly: AssemblyTitle("MVS Print")]
[assembly: AssemblyDescription("Launcher del motor local de impresion de MVS Commerce")]
[assembly: AssemblyCompany("MVS Commerce")]
[assembly: AssemblyProduct("MVS Print")]
[assembly: AssemblyVersion("1.0.2.0")]
[assembly: AssemblyFileVersion("1.0.2.0")]
[assembly: AssemblyCopyright("Copyright © 2026 MVS Commerce")]

namespace MvsPrint {
    public interface IEngine {
        string Find();
        bool Running(string path);
        bool Ready();
        void Start(string path);
        void Delay();
    }

    public static class Lifecycle {
        public static void Ensure(IEngine engine, bool start) {
            string path = engine.Find();
            if (String.IsNullOrEmpty(path)) throw new InvalidOperationException("No se encuentra el motor de MVS Print. Reinstale MVS Print.");
            bool running = engine.Running(path);
            if (running && engine.Ready()) return;
            if (!running) {
                if (!start) throw new InvalidOperationException("MVS Print no está iniciado.");
                engine.Start(path);
            }
            for (int attempt = 0; attempt < 15; attempt++) {
                if (engine.Ready()) return;
                engine.Delay();
            }
            throw new InvalidOperationException("El motor está iniciado pero no responde. Revise MVS Print antes de volver a imprimir.");
        }
    }

    public sealed class WindowsEngine : IEngine {
        public string Find() {
            foreach (RegistryView view in new[] { RegistryView.Registry64, RegistryView.Registry32 }) {
                using (RegistryKey root = RegistryKey.OpenBaseKey(RegistryHive.LocalMachine, view))
                using (RegistryKey key = root.OpenSubKey(@"Software\QZ Tray")) {
                    string path = key == null ? null : key.GetValue("") as string;
                    if (Valid(path)) return Path.GetFullPath(path);
                }
            }
            string fallback = Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.ProgramFiles), "QZ Tray");
            return Valid(fallback) ? fallback : null;
        }

        private static bool Valid(string path) {
            return !String.IsNullOrWhiteSpace(path) && Path.IsPathRooted(path) && !path.StartsWith(@"\\") &&
                File.Exists(Path.Combine(path, "qz-tray.exe")) &&
                File.Exists(Path.Combine(path, "qz-tray.jar"));
        }

        public bool Running(string path) {
            int session = Process.GetCurrentProcess().SessionId;
            string jar = Path.Combine(path, "qz-tray.jar");
            string exe = Path.Combine(path, "qz-tray.exe");
            using (ManagementObjectSearcher query = new ManagementObjectSearcher(
                "SELECT ExecutablePath,CommandLine,SessionId FROM Win32_Process WHERE Name='qz-tray.exe' OR Name='java.exe' OR Name='javaw.exe'")) {
                foreach (ManagementObject process in query.Get()) {
                    using (process) {
                        if (Convert.ToInt32(process["SessionId"]) != session) continue;
                        string executable = process["ExecutablePath"] as string ?? "";
                        string command = process["CommandLine"] as string ?? "";
                        if (String.Equals(executable, exe, StringComparison.OrdinalIgnoreCase) ||
                            command.IndexOf(jar, StringComparison.OrdinalIgnoreCase) >= 0 ||
                            command.Replace('/', '\\').IndexOf(jar, StringComparison.OrdinalIgnoreCase) >= 0) return true;
                    }
                }
            }
            return false;
        }

        // Read-only QZ getVersion over loopback WS. No TLS bypass, certificate grant or print call.
        public bool Ready() {
            foreach (int port in new[] { 8182, 8283, 8384, 8485 }) {
                if (Probe(port).GetAwaiter().GetResult()) return true;
            }
            return false;
        }
        private static async Task<bool> Probe(int port) {
            using (ClientWebSocket socket = new ClientWebSocket())
            using (CancellationTokenSource timeout = new CancellationTokenSource(350)) {
                try {
                    await socket.ConnectAsync(new Uri("ws://127.0.0.1:" + port), timeout.Token);
                    byte[] request = Encoding.UTF8.GetBytes("{\"call\":\"getVersion\",\"uid\":\"mvs-launcher-probe\"}");
                    await socket.SendAsync(new ArraySegment<byte>(request), WebSocketMessageType.Text, true, timeout.Token);
                    byte[] response = new byte[4096];
                    int used = 0;
                    WebSocketReceiveResult received;
                    do {
                        received = await socket.ReceiveAsync(new ArraySegment<byte>(response, used, response.Length - used), timeout.Token);
                        used += received.Count;
                        if (received.MessageType != WebSocketMessageType.Text || used == response.Length) return false;
                    } while (!received.EndOfMessage);
                    var data = new JavaScriptSerializer().Deserialize<Dictionary<string,object>>(Encoding.UTF8.GetString(response, 0, used));
                    return data.ContainsKey("uid") && (string)data["uid"] == "mvs-launcher-probe" &&
                        data.ContainsKey("result") && Convert.ToString(data["result"]).StartsWith("2.");
                } catch { return false; }
            }
        }
        public void Start(string path) {
            Process process = Process.Start(new ProcessStartInfo(Path.Combine(path, "qz-tray.exe")) {
                WorkingDirectory = path, UseShellExecute = false, CreateNoWindow = true,
            });
            if (process == null) throw new InvalidOperationException("No fue posible iniciar el motor de MVS Print.");
            process.Dispose();
        }
        public void Delay() { Thread.Sleep(200); }
    }

    public static class Program {
        [STAThread]
        public static int Main(string[] args) {
            bool configure = Array.IndexOf(args, "--configure-trust") >= 0;
            bool remove = Array.IndexOf(args, "--remove-trust") >= 0;
            bool quiet = configure || remove || Array.IndexOf(args, "--check") >= 0 || Array.IndexOf(args, "--verify") >= 0;
            bool acquired = false;
            using (Mutex mutex = new Mutex(false, @"Local\MvsPrintLauncher")) {
                try {
                    try { acquired = mutex.WaitOne(TimeSpan.FromSeconds(35)); }
                    catch (AbandonedMutexException) { acquired = true; }
                    if (!acquired) throw new InvalidOperationException("MVS Print ya está iniciándose. Espere unos segundos.");
                    if (configure || remove) { Trust.Configure(remove); return 0; }
                    Lifecycle.Ensure(new WindowsEngine(), Array.IndexOf(args, "--check") < 0);
                    return 0;
                } catch (Exception error) {
                    if (!quiet) ShowError(error.Message);
                    return 1;
                } finally {
                    if (acquired) mutex.ReleaseMutex();
                }
            }
        }

        private static void ShowError(string message) {
            Application.EnableVisualStyles();
            using (Form form = new Form()) {
                form.Text = "MVS Print";
                form.ClientSize = new Size(440, 210);
                form.StartPosition = FormStartPosition.CenterScreen;
                form.FormBorderStyle = FormBorderStyle.FixedDialog;
                form.MaximizeBox = false;
                form.MinimizeBox = false;
                form.BackColor = Color.White;
                form.Icon = Icon.ExtractAssociatedIcon(Assembly.GetExecutingAssembly().Location);
                Label title = new Label { Text = "MVS Print", Left = 20, Top = 20, Width = 400, Height = 32, Font = new Font("Segoe UI", 16, FontStyle.Bold) };
                Label text = new Label { Text = message, Left = 20, Top = 64, Width = 400, Height = 82, Font = new Font("Segoe UI", 10) };
                Button close = new Button { Text = "Cerrar", Left = 300, Top = 156, Width = 120, Height = 40,
                    BackColor = ColorTranslator.FromHtml(Brand.Gold), ForeColor = Color.FromArgb(17,17,17), FlatStyle = FlatStyle.Flat };
                close.Click += delegate { form.Close(); };
                form.Controls.AddRange(new Control[] {title, text, close});
                form.AcceptButton = close;
                form.ShowDialog();
            }
        }
    }
}
