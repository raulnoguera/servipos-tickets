import Cocoa
import WebKit

struct Shot: Codable { let name: String; let path: String; let w: Int; let h: Int; let mobile: Bool?; let wait: Int?; let js: String?; let after: Int?; let scale: Double? }
struct Session: Codable { let email: String?; let shots: [Shot] }
struct Config: Codable { let base: String; let out: String; let sessions: [Session] }

final class Nav: NSObject, WKNavigationDelegate {
    var cont: CheckedContinuation<Void, Never>?
    func done() { cont?.resume(); cont = nil }
    func webView(_ w: WKWebView, didFinish n: WKNavigation!) { done() }
    func webView(_ w: WKWebView, didFail n: WKNavigation!, withError e: Error) { print("  fail:", e.localizedDescription); done() }
    func webView(_ w: WKWebView, didFailProvisionalNavigation n: WKNavigation!, withError e: Error) { print("  fail:", e.localizedDescription); done() }
}

var windows: [NSWindow] = []
let iPhoneUA = "Mozilla/5.0 (iPhone; CPU iPhone OS 18_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.5 Mobile/15E148 Safari/604.1"

@MainActor func run() async {
    let args = CommandLine.arguments
    let cfg = try! JSONDecoder().decode(Config.self, from: Data(contentsOf: URL(fileURLWithPath: args[1])))
    let only: Set<String>? = args.count > 2 ? Set(args[2].split(separator: ",").map(String.init)) : nil
    let password = ProcessInfo.processInfo.environment["CAPTURE_PASSWORD"] ?? ""
    try? FileManager.default.createDirectory(atPath: cfg.out, withIntermediateDirectories: true)

    for session in cfg.sessions {
        let shots = session.shots.filter { only == nil || only!.contains($0.name) }
        if shots.isEmpty { continue }
        let conf = WKWebViewConfiguration()
        conf.websiteDataStore = .nonPersistent()
        // La ventana está fuera de pantalla y WebKit pausa requestAnimationFrame y las transiciones.
        // También se anotan las violaciones de la política de contenido (CSP) de cada página.
        conf.userContentController.addUserScript(WKUserScript(source: """
            window.requestAnimationFrame = (cb) => setTimeout(() => cb(performance.now()), 16);
            window.cancelAnimationFrame = (id) => clearTimeout(id);
            window.__csp = [];
            document.addEventListener('securitypolicyviolation', (e) => window.__csp.push(e.violatedDirective + ' <- ' + (e.blockedURI || 'inline') + ' ' + (e.sample || '').slice(0, 50)));
            document.addEventListener('DOMContentLoaded', () => { const l = document.createElement('link'); l.rel = 'stylesheet'; l.href = 'data:text/css,*%7Btransition:none!important%7D'; });
            """, injectionTime: .atDocumentStart, forMainFrameOnly: false))
        let wv = WKWebView(frame: NSRect(x: 0, y: 0, width: 1440, height: 900), configuration: conf)
        let win = NSWindow(contentRect: wv.frame, styleMask: [.borderless], backing: .buffered, defer: false)
        win.isReleasedWhenClosed = false
        win.animationBehavior = .none
        windows.append(win)
        win.appearance = NSAppearance(named: .aqua)
        win.contentView = wv
        win.setFrameOrigin(NSPoint(x: -6000, y: -6000))
        win.orderFrontRegardless()
        let nav = Nav()
        wv.navigationDelegate = nav
        func load(_ path: String) async {
            await withCheckedContinuation { c in nav.cont = c; wv.load(URLRequest(url: URL(string: cfg.base + path)!)) }
        }

        if let email = session.email {
            await load("/login")
            let status = try? await wv.callAsyncJavaScript("""
                await fetch('/sanctum/csrf-cookie', { credentials: 'same-origin' });
                const x = decodeURIComponent((document.cookie.match(/XSRF-TOKEN=([^;]+)/) || [])[1] || '');
                const r = await fetch('/api/v1/auth/login', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-XSRF-TOKEN': x }, body: JSON.stringify({ email, password }) });
                return r.status;
                """, arguments: ["email": email, "password": password], in: nil, contentWorld: .page)
            print("login \(email): \(String(describing: status ?? "error"))")
        }

        for shot in shots {
            wv.customUserAgent = (shot.mobile ?? false) ? iPhoneUA : nil
            win.setContentSize(NSSize(width: shot.w, height: shot.h))
            wv.frame = NSRect(x: 0, y: 0, width: shot.w, height: shot.h)
            await load(shot.path)
            try? await Task.sleep(nanoseconds: UInt64(shot.wait ?? 2500) * 1_000_000)
            if let js = shot.js {
                do { _ = try await wv.callAsyncJavaScript(js, arguments: [:], in: nil, contentWorld: .page) } catch { print("  js error \(shot.name):", error) }
                try? await Task.sleep(nanoseconds: UInt64(shot.after ?? 1500) * 1_000_000)
            }
            let csp = (try? await wv.callAsyncJavaScript("return [...new Set(window.__csp || [])].join(' || ')", arguments: [:], in: nil, contentWorld: .page)) as? String ?? ""
            let sc = WKSnapshotConfiguration()
            sc.snapshotWidth = NSNumber(value: Double(shot.w) * (shot.scale ?? 1))
            sc.afterScreenUpdates = true
            do {
                let img = try await wv.takeSnapshot(configuration: sc)
                guard let tiff = img.tiffRepresentation, let rep = NSBitmapImageRep(data: tiff), let png = rep.representation(using: .png, properties: [:]) else { continue }
                try png.write(to: URL(fileURLWithPath: "\(cfg.out)/\(shot.name).png"))
                print("ok \(shot.name) \(rep.pixelsWide)x\(rep.pixelsHigh) | CSP: \(csp.isEmpty ? "sin violaciones" : csp)")
            } catch { print("  snapshot error \(shot.name):", error) }
        }
        win.orderOut(nil)
    }
}

setbuf(stdout, nil)
let app = NSApplication.shared
app.setActivationPolicy(.accessory)
Task { @MainActor in await run(); exit(0) }
app.run()
