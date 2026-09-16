using System;
using MvsPrint;
public sealed class FakeEngine : IEngine {
    public string Path = "engine";
    public bool IsRunning, IsReady, CanStart = true;
    public int Starts, Probes;
    public string Find() { return Path; }
    public bool Running(string path) { return IsRunning; }
    public bool Ready() { Probes++; return IsReady || (Starts > 0 && CanStart); }
    public void Start(string path) { Starts++; IsRunning = true; }
    public void Delay() {}
}
public static class LauncherTests {
    static void Assert(bool value) { if (!value) throw new Exception("Launcher assertion failed"); }
    public static int Main() {
        FakeEngine ready = new FakeEngine {IsRunning=true,IsReady=true};
        Lifecycle.Ensure(ready,true); Assert(ready.Starts==0);
        FakeEngine stopped = new FakeEngine();
        Lifecycle.Ensure(stopped,true); Assert(stopped.Starts==1);
        Lifecycle.Ensure(stopped,true); Assert(stopped.Starts==1);
        FakeEngine waiting = new FakeEngine {IsRunning=true};
        try { Lifecycle.Ensure(waiting,true); throw new Exception("Expected timeout"); } catch (InvalidOperationException) {}
        Assert(waiting.Starts==0 && waiting.Probes==16);
        FakeEngine missing = new FakeEngine {Path=null};
        try { Lifecycle.Ensure(missing,true); throw new Exception("Expected missing engine"); } catch (InvalidOperationException) {}
        Assert(missing.Starts==0);
        FakeEngine failed = new FakeEngine {CanStart=false};
        try { Lifecycle.Ensure(failed,true); throw new Exception("Expected startup failure"); } catch (InvalidOperationException) {}
        Assert(failed.Starts==1);
        FakeEngine check = new FakeEngine();
        try { Lifecycle.Ensure(check,false); throw new Exception("Expected stopped check"); } catch (InvalidOperationException) {}
        Assert(check.Starts==0);
        string original = "other=true\r\nauthcert.override=C:/other/root.crt\r\n";
        string added = Trust.Edit(original,"C:/MVS/public.crt",false);
        Assert(added.Contains("C:/other/root.crt;C:/MVS/public.crt"));
        Assert(Trust.Edit(added,"C:/MVS/public.crt",false)==added);
        Assert(Trust.Edit(added,"C:/MVS/public.crt",true)==original);
        Assert(Trust.Edit("","C:/MVS/public.crt",false).Contains("authcert.override=C:/MVS/public.crt"));
        Console.WriteLine("LAUNCHER_TESTS=PASS (running, stopped, repeated launch, pending, missing, failed, read-only check)");
        return 0;
    }
}
