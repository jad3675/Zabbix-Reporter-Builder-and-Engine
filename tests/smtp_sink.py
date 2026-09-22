"""Minimal SMTP sink for tests: AUTH PLAIN/LOGIN, no TLS. Writes each message to DIR."""
import socketserver, sys, os, base64, time

DIR = sys.argv[2]

class H(socketserver.StreamRequestHandler):
    def w(self, s): self.wfile.write((s + "\r\n").encode())
    def handle(self):
        self.w("220 sink ESMTP")
        user = None; mail = []; rcpt = []
        while True:
            line = self.rfile.readline().decode(errors="replace").rstrip("\r\n")
            if not line: return
            cmd = line.upper()
            if cmd.startswith("EHLO") or cmd.startswith("HELO"):
                self.wfile.write(b"250-sink\r\n250-AUTH PLAIN LOGIN\r\n250 8BITMIME\r\n")
            elif cmd.startswith("AUTH PLAIN"):
                parts = line.split(" ")
                data = parts[2] if len(parts) > 2 else (self.w("334 ") or self.rfile.readline().decode().strip())
                _, u, p = base64.b64decode(data).decode().split("\0")
                user = (u, p); self.w("235 ok")
            elif cmd.startswith("AUTH LOGIN"):
                self.w("334 VXNlcm5hbWU6"); u = base64.b64decode(self.rfile.readline().strip()).decode()
                self.w("334 UGFzc3dvcmQ6"); p = base64.b64decode(self.rfile.readline().strip()).decode()
                user = (u, p); self.w("235 ok")
            elif cmd.startswith("MAIL FROM"): mail.append(line); self.w("250 ok")
            elif cmd.startswith("RCPT TO"): rcpt.append(line); self.w("250 ok")
            elif cmd == "DATA":
                self.w("354 go"); body = []
                while True:
                    l = self.rfile.readline().decode(errors="replace")
                    if l in (".\r\n", ".\n", ""): break
                    body.append(l)
                name = os.path.join(DIR, "%d-%d.eml" % (time.time() * 1000, os.getpid()))
                with open(name, "w") as f:
                    f.write("X-Auth: %s\n" % (user and "%s:%s" % user))
                    f.write("X-Rcpt: %s\n" % ",".join(rcpt)); f.write("".join(body))
                self.w("250 queued")
            elif cmd == "QUIT": self.w("221 bye"); return
            elif cmd == "RSET" or cmd == "NOOP": self.w("250 ok")
            else: self.w("502 no")

socketserver.ThreadingTCPServer.allow_reuse_address = True
socketserver.ThreadingTCPServer(("127.0.0.1", int(sys.argv[1])), H).serve_forever()
