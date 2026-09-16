using System;
using System.IO;
using System.Security.Cryptography.X509Certificates;
using System.Text;
using System.Text.RegularExpressions;
using System.Collections.Generic;
namespace MvsPrint {
    public static class Trust {
        public static string Edit(string text, string certificate, bool remove) {
            certificate = certificate.Replace('\\','/');
            if (certificate.IndexOfAny(new[] {';', '|', '\r', '\n'}) >= 0) throw new InvalidOperationException("Ruta de certificado inválida.");
            MatchCollection matches = Regex.Matches(text, @"(?m)^(authcert\.override|trustedRootCert)[ \t]*[=:][ \t]*([^\r\n]*)");
            if (matches.Count > 1) throw new InvalidOperationException("La configuración del motor contiene varias reglas de confianza. Se conserva para revisión.");
            string value = matches.Count == 0 ? "" : matches[0].Groups[2].Value;
            if (value.EndsWith("\\")) throw new InvalidOperationException("La confianza existente usa varias líneas. Se conserva para revisión.");
            List<string> parts = new List<string>();
            foreach (string part in value.Split(';')) if (part.Length > 0 && part != certificate) parts.Add(part);
            if (!remove) parts.Add(certificate);
            string replacement = parts.Count == 0 ? "" : "authcert.override=" + String.Join(";",parts);
            if (matches.Count == 0) return remove ? text : text.TrimEnd() + "\r\n" + replacement + "\r\n";
            Match match = matches[0];
            return text.Substring(0,match.Index) + replacement + text.Substring(match.Index+match.Length);
        }
        public static void Configure(bool remove) {
            string root = new WindowsEngine().Find();
            if (root == null) throw new InvalidOperationException("No se encuentra el motor de impresión.");
            string certPath = Path.Combine(AppDomain.CurrentDomain.BaseDirectory,"mvs-public-certificate.crt");
            string pem = File.ReadAllText(certPath);
            Match match = Regex.Match(pem, @"-----BEGIN CERTIFICATE-----([\s\S]+?)-----END CERTIFICATE-----");
            if (!match.Success || pem.Contains("PRIVATE KEY")) throw new InvalidOperationException("Se requiere el certificado público de MVS.");
            using (X509Certificate2 cert = new X509Certificate2(Convert.FromBase64String(match.Groups[1].Value))) {
                if (!remove && (cert.NotAfter <= DateTime.Now || cert.NotBefore > DateTime.Now)) throw new InvalidOperationException("Certificado fuera de vigencia.");
                string path = Path.Combine(root,"qz-tray.properties");
                Encoding encoding = Encoding.GetEncoding(28591);
                string text = File.Exists(path) ? File.ReadAllText(path,encoding) : "";
                string updated = Edit(text,certPath,remove);
                if (updated != text) {
                    if (File.Exists(path)) File.Copy(path,path+".mvs-print-"+DateTime.UtcNow.ToString("yyyyMMddHHmmssfffffff")+".bak");
                    string temp = path + ".mvs-print-temp";
                    File.WriteAllText(temp,updated,encoding);
                    File.Move(temp,path,true);
                }
            }
        }
    }
}
