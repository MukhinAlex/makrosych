using System;
using System.Collections.Generic;
using System.Diagnostics;
using System.Drawing;
using System.Globalization;
using System.IO;
using System.Net.Sockets;
using System.Reflection;
using System.Text;
using System.Threading;
using System.Windows.Forms;

/// <summary>
/// Макросыч — лаунчер.
///
/// Запускает локальный сервер PHP без консольного окна, открывает интерфейс в браузере
/// и держит значок в области уведомлений, из которого программу можно остановить.
///
/// Компилируется встроенным в Windows компилятором C# (см. build.bat), поэтому
/// для сборки не нужно ничего устанавливать. Язык — C# 5: старый csc.exe из .NET
/// Framework 4.x не понимает более новых возможностей.
///
/// Что происходит при запуске, лаунчер пишет в %TEMP%\makrosych-launcher.log —
/// этот файл стоит посмотреть, если программа не запускается.
/// </summary>
internal static class Makrosych
{
    private const int Port = 8787;
    private const string Url = "http://127.0.0.1:8787/";
    private const string Title = "Макросыч";
    private const int WaitMilliseconds = 30000;

    private static string _root;
    private static string _log;
    private static string _launcherLog;
    private static string _lastError = "";
    private static NotifyIcon _tray;

    [STAThread]
    private static void Main(string[] args)
    {
        Application.EnableVisualStyles();

        _root = Path.GetDirectoryName(Application.ExecutablePath);
        if (string.IsNullOrEmpty(_root))
        {
            _root = ".";
        }
        _log = Path.Combine(Path.GetTempPath(), "makrosych-server.log");
        _launcherLog = Path.Combine(Path.GetTempPath(), "makrosych-launcher.log");

        // «Макросыч.exe --stop» — остановить сервер и выйти, без значка в области уведомлений.
        // Нужен, когда программу запустили через start.bat: значок тогда не появляется.
        if (args.Length > 0 && string.Equals(args[0], "--stop", StringComparison.OrdinalIgnoreCase))
        {
            StopServer();

            return;
        }

        Log("--- запуск лаунчера, каталог " + _root);

        string php = FindPhp();
        if (php == null)
        {
            Log("не найден php.exe");
            MessageBox.Show(
                "Не найден PHP." + Environment.NewLine + Environment.NewLine
                + "Положите переносимую сборку в:" + Environment.NewLine
                + Path.Combine(_root, "runtime", "php", "php.exe"),
                Title, MessageBoxButtons.OK, MessageBoxIcon.Error);

            return;
        }

        bool running = ServerAlive();
        int startedPid = 0;

        if (!running)
        {
            // Порт мог остаться занятым от прошлого запуска (в том числе неудачного):
            // наш сервер, который перестал отвечать, останавливаем и запускаем заново.
            // Чужую программу на нашем порту не трогаем — о ней и сообщаем.
            string foreign = ForeignListener(php);
            if (foreign.Length > 0)
            {
                Log("порт занят другой программой: " + foreign);

                MessageBox.Show(
                    "Порт " + Port.ToString(CultureInfo.InvariantCulture)
                    + " занят другой программой:" + Environment.NewLine + foreign
                    + Environment.NewLine + Environment.NewLine
                    + "Закройте эту программу и запустите Макросыч снова."
                    + Environment.NewLine + Environment.NewLine
                    + "Журнал лаунчера: " + _launcherLog,
                    Title, MessageBoxButtons.OK, MessageBoxIcon.Warning);

                return;
            }

            StopOwnServers(php);

            Log("сервер не отвечает — запускаем");
            startedPid = StartServer(php);
            Log("запущен процесс cmd.exe, код " + startedPid.ToString(CultureInfo.InvariantCulture));

            running = WaitForServer();
            Log(running ? "сервер ответил" : "сервер не ответил за 30 секунд");
        }
        else
        {
            Log("сервер уже запущен");
        }

        if (!running)
        {
            // Порт слушает наш же php.exe, но проверка до него не достучалась
            // (например, запрос перехватил антивирус). Лучше открыть браузер и показать
            // значок, чем оставить работающий сервер без способа его остановить.
            if (ListenerIsOurs(php))
            {
                Log("проверка не отвечает, но порт слушает наш php.exe — считаем, что сервер запущен");
                running = true;
            }
        }

        if (!running)
        {
            if (startedPid > 0)
            {
                KillTree(startedPid);
            }

            Log("неудача: сервер не запустился. Последняя ошибка проверки: " + _lastError);

            MessageBox.Show(
                "Макросыч не запустился за 30 секунд." + Environment.NewLine + Environment.NewLine
                + "Журналы:" + Environment.NewLine + _launcherLog + Environment.NewLine + _log
                + Environment.NewLine + Environment.NewLine
                + "Запустите start.bat — он покажет сообщение об ошибке на экране.",
                Title, MessageBoxButtons.OK, MessageBoxIcon.Warning);

            return;
        }

        ShowTray();
        OpenBrowser();
        Log("значок показан, браузер открыт");

        Application.Run();
    }

    /// <summary>Путь к php.exe: сначала сборка внутри программы, затем OSPanel на стенде разработки.</summary>
    private static string FindPhp()
    {
        string bundled = Path.Combine(_root, Path.Combine("runtime", Path.Combine("php", "php.exe")));
        if (File.Exists(bundled))
        {
            return bundled;
        }

        const string ospanel = @"C:\OSPanel\modules\PHP-8.3\php.exe";

        return File.Exists(ospanel) ? ospanel : null;
    }

    /// <summary>
    /// Запускает сервер скрыто.
    ///
    /// Именно через cmd и с перенаправлением вывода в журнал: без этого встроенный
    /// сервер PHP не поднимается (проверено — ни php-win.exe, ни прямой запуск php.exe
    /// без окна сервер не запускают).
    /// </summary>
    private static int StartServer(string php)
    {
        string web = Path.Combine(_root, "app\\Web");

        string command = "cd /d \"" + _root + "\""
            + " && \"" + php + "\" -S 127.0.0.1:" + Port.ToString(CultureInfo.InvariantCulture)
            + " -t \"" + web + "\" \"" + Path.Combine(web, "router.php") + "\""
            + " > \"" + _log + "\" 2>&1";

        ProcessStartInfo info = new ProcessStartInfo("cmd.exe", "/c " + command);
        info.UseShellExecute = false;
        info.CreateNoWindow = true;
        info.WorkingDirectory = _root;

        Process process = Process.Start(info);

        return process == null ? 0 : process.Id;
    }

    /// <summary>
    /// Отвечает ли приложение по своему адресу.
    ///
    /// Проверка идёт сырым запросом по TCP, а не через WebClient: HTTP-стек .NET
    /// на localhost в этом окружении отвечает не всегда, хотя сервер работает
    /// и браузер страницу открывает.
    /// </summary>
    private static bool ServerAlive()
    {
        _lastError = "";

        try
        {
            string response = HttpGet("/");

            return response.IndexOf(" 200 ", StringComparison.Ordinal) >= 0
                && response.IndexOf(Title, StringComparison.Ordinal) >= 0;
        }
        catch (Exception error)
        {
            _lastError = error.GetType().Name + ": " + error.Message;

            return false;
        }
    }

    /// <summary>
    /// Чужая программа, занявшая наш порт. Пусто — порт свободен или его слушает наш php.exe.
    ///
    /// На Windows порт может слушать сразу несколько процессов: PHP ставит SO_REUSEADDR,
    /// и второй сервер спокойно встаёт на тот же порт, хотя запросы уходят только одному.
    /// Поэтому важно не «порт занят», а «кто именно его слушает».
    /// </summary>
    private static string ForeignListener(string php)
    {
        foreach (int pid in ListenerPids())
        {
            string path = ProcessPath(pid);

            if (path.Length == 0)
            {
                return "не удалось определить программу (код процесса "
                    + pid.ToString(CultureInfo.InvariantCulture) + ")";
            }

            if (!string.Equals(path, php, StringComparison.OrdinalIgnoreCase))
            {
                return path;
            }
        }

        return "";
    }

    /// <summary>Слушает ли порт наш собственный php.exe.</summary>
    private static bool ListenerIsOurs(string php)
    {
        foreach (int pid in ListenerPids())
        {
            if (string.Equals(ProcessPath(pid), php, StringComparison.OrdinalIgnoreCase))
            {
                return true;
            }
        }

        return false;
    }

    /// <summary>Останавливает наши серверы, оставшиеся от прошлых запусков и не отвечающие.</summary>
    private static void StopOwnServers(string php)
    {
        foreach (int pid in ListenerPids())
        {
            if (string.Equals(ProcessPath(pid), php, StringComparison.OrdinalIgnoreCase))
            {
                RunHidden("taskkill", "/PID " + pid.ToString(CultureInfo.InvariantCulture) + " /T /F");
                Log("остановлен неотвечающий сервер, код " + pid.ToString(CultureInfo.InvariantCulture));
            }
        }
    }

    /// <summary>Путь к программе процесса; пусто, если определить не удалось.</summary>
    private static string ProcessPath(int pid)
    {
        try
        {
            using (Process process = Process.GetProcessById(pid))
            {
                ProcessModule module = process.MainModule;

                return module == null ? "" : module.FileName;
            }
        }
        catch (Exception)
        {
            return "";
        }
    }

    /// <summary>Простой HTTP-запрос по TCP: без прокси, без кэша, без ожидания закрытия соединения.</summary>
    private static string HttpGet(string path)
    {
        using (TcpClient client = new TcpClient())
        {
            IAsyncResult connect = client.BeginConnect("127.0.0.1", Port, null, null);
            if (!connect.AsyncWaitHandle.WaitOne(2000))
            {
                throw new IOException("нет ответа при подключении к 127.0.0.1:" + Port);
            }

            client.EndConnect(connect);
            client.ReceiveTimeout = 5000;

            using (NetworkStream stream = client.GetStream())
            {
                byte[] request = Encoding.ASCII.GetBytes(
                    "GET " + path + " HTTP/1.0\r\nHost: 127.0.0.1\r\nConnection: close\r\n\r\n");
                stream.Write(request, 0, request.Length);

                MemoryStream data = new MemoryStream();
                byte[] buffer = new byte[8192];
                int read;

                // 8 КБ достаточно: заголовки и начало страницы. Дальше не читаем,
                // чтобы не ждать, пока сервер закроет соединение.
                while (data.Length < 8192 && (read = stream.Read(buffer, 0, buffer.Length)) > 0)
                {
                    data.Write(buffer, 0, read);
                }

                return Encoding.UTF8.GetString(data.ToArray());
            }
        }
    }

    private static bool WaitForServer()
    {
        Stopwatch watch = Stopwatch.StartNew();
        string firstError = "";

        while (watch.ElapsedMilliseconds < WaitMilliseconds)
        {
            Thread.Sleep(500);

            if (ServerAlive())
            {
                return true;
            }

            if (firstError.Length == 0 && _lastError.Length > 0)
            {
                firstError = _lastError;
            }
        }

        if (firstError.Length > 0)
        {
            Log("первая ошибка проверки: " + firstError);
        }

        return false;
    }

    private static void OpenBrowser()
    {
        try
        {
            Process.Start(Url);
        }
        catch (Exception)
        {
            // Браузер по умолчанию может быть не настроен — это не повод падать
        }
    }

    private static void ShowTray()
    {
        ContextMenuStrip menu = new ContextMenuStrip();
        menu.Items.Add("Открыть Макросыч", null, delegate { OpenBrowser(); });
        menu.Items.Add("Создать ярлык на рабочем столе", null, delegate
        {
            MessageBox.Show(CreateShortcut(), Title, MessageBoxButtons.OK, MessageBoxIcon.Information);
        });
        menu.Items.Add(new ToolStripSeparator());
        menu.Items.Add("Остановить и выйти", null, delegate
        {
            StopServer();
            Exit();
        });

        _tray = new NotifyIcon();
        _tray.Icon = Icon.ExtractAssociatedIcon(Application.ExecutablePath);
        _tray.Text = "Макросыч — работает";
        _tray.ContextMenuStrip = menu;
        _tray.Visible = true;
        _tray.DoubleClick += delegate { OpenBrowser(); };
        _tray.ShowBalloonTip(5000, "Макросыч запущен",
            "Программа работает в браузере. Остановить — правой кнопкой по значку.", ToolTipIcon.Info);
    }

    private static void Exit()
    {
        if (_tray != null)
        {
            _tray.Visible = false;
            _tray.Dispose();
            _tray = null;
        }

        Application.ExitThread();
    }

    /// <summary>Завершает сервер: тот процесс php.exe, который слушает наш порт.</summary>
    private static void StopServer()
    {
        foreach (int pid in ListenerPids())
        {
            RunHidden("taskkill", "/PID " + pid.ToString(CultureInfo.InvariantCulture) + " /T /F");
            Log("остановлен процесс php.exe, код " + pid.ToString(CultureInfo.InvariantCulture));
        }
    }

    /// <summary>Завершает процесс и всё, что он запустил (наш cmd.exe с сервером).</summary>
    private static void KillTree(int pid)
    {
        RunHidden("taskkill", "/PID " + pid.ToString(CultureInfo.InvariantCulture) + " /T /F");
        Log("остановлен незапустившийся процесс, код " + pid.ToString(CultureInfo.InvariantCulture));
    }

    private static List<int> ListenerPids()
    {
        List<int> pids = new List<int>();
        string output = RunHidden("netstat", "-ano");
        string marker = ":" + Port.ToString(CultureInfo.InvariantCulture);

        string[] lines = output.Split('\n');
        foreach (string line in lines)
        {
            if (line.IndexOf(marker, StringComparison.Ordinal) < 0
                || line.IndexOf("LISTENING", StringComparison.OrdinalIgnoreCase) < 0)
            {
                continue;
            }

            string[] parts = line.Split(new char[] { ' ', '\t', '\r' }, StringSplitOptions.RemoveEmptyEntries);
            if (parts.Length == 0)
            {
                continue;
            }

            int pid;
            if (int.TryParse(parts[parts.Length - 1], NumberStyles.Integer, CultureInfo.InvariantCulture, out pid))
            {
                pids.Add(pid);
            }
        }

        return pids;
    }

    /// <summary>Запускает команду без окна и возвращает её вывод.</summary>
    private static string RunHidden(string program, string arguments)
    {
        try
        {
            ProcessStartInfo info = new ProcessStartInfo(program, arguments);
            info.UseShellExecute = false;
            info.CreateNoWindow = true;
            info.RedirectStandardOutput = true;

            using (Process process = Process.Start(info))
            {
                string output = process.StandardOutput.ReadToEnd();
                process.WaitForExit();

                return output;
            }
        }
        catch (Exception)
        {
            return string.Empty;
        }
    }

    /// <summary>Запись в журнал лаунчера — по нему разбираются случаи «не запускается».</summary>
    private static void Log(string message)
    {
        try
        {
            File.AppendAllText(_launcherLog,
                DateTime.Now.ToString("yyyy-MM-dd HH:mm:ss") + "  " + message + Environment.NewLine);
        }
        catch (Exception)
        {
            // журнал — вспомогательный, из-за него падать нельзя
        }
    }

    /// <summary>
    /// Кладёт ярлык на рабочий стол через COM-объект WScript.Shell.
    /// Вызов идёт напрямую (не через .vbs), поэтому отключённый Windows Script Host
    /// созданию ярлыка не мешает.
    /// </summary>
    private static string CreateShortcut()
    {
        try
        {
            Type shellType = Type.GetTypeFromProgID("WScript.Shell");
            if (shellType == null)
            {
                return "Не удалось создать ярлык: системный объект WScript.Shell недоступен.";
            }

            string desktop = Environment.GetFolderPath(Environment.SpecialFolder.DesktopDirectory);
            string link = Path.Combine(desktop, "Макросыч.lnk");

            object shell = Activator.CreateInstance(shellType);
            object shortcut = shellType.InvokeMember("CreateShortcut", BindingFlags.InvokeMethod,
                null, shell, new object[] { link });

            Type shortcutType = shortcut.GetType();
            shortcutType.InvokeMember("TargetPath", BindingFlags.SetProperty, null, shortcut,
                new object[] { Application.ExecutablePath });
            shortcutType.InvokeMember("WorkingDirectory", BindingFlags.SetProperty, null, shortcut,
                new object[] { _root });
            shortcutType.InvokeMember("Description", BindingFlags.SetProperty, null, shortcut,
                new object[] { "Макросыч — сценарии обработки Excel" });
            shortcutType.InvokeMember("IconLocation", BindingFlags.SetProperty, null, shortcut,
                new object[] { Application.ExecutablePath + ",0" });
            shortcutType.InvokeMember("Save", BindingFlags.InvokeMethod, null, shortcut, null);

            return "Ярлык «Макросыч» создан на рабочем столе.";
        }
        catch (Exception error)
        {
            return "Не удалось создать ярлык: " + error.Message
                + Environment.NewLine + Environment.NewLine
                + "Создайте его вручную: правой кнопкой по «Макросыч.exe» → «Отправить» → «Рабочий стол».";
        }
    }
}
