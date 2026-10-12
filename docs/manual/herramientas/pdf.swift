// Convierte páginas HTML a PDF con el motor de impresión de macOS (WebKit) y arma el manual:
//   pdf render <entrada.html> <salida.pdf> <margenSup> <margenInf> <margenLat>
//   pdf paginas <archivo.pdf>                      → texto de cada página (para armar el índice)
//   pdf png <archivo.pdf> <página> <salida.png> [escala]
//   pdf hoja <archivo.pdf> <primera> <cuántas> <salida.png> [escala]   → varias páginas en una imagen
//   pdf armar <portada.pdf> <cuerpo.pdf> <salida.pdf> <título del pie> [marcadores.tsv]
//     marcadores.tsv: nivel <tab> página del cuerpo <tab> título (panel de marcadores del PDF)
import Cocoa
import WebKit
import PDFKit

final class Printer: NSObject, WKNavigationDelegate {
    let input: URL, output: URL, top: CGFloat, bottom: CGFloat, side: CGFloat
    var web: WKWebView!
    var window: NSWindow!
    init(input: URL, output: URL, top: CGFloat, bottom: CGFloat, side: CGFloat) {
        self.input = input; self.output = output; self.top = top; self.bottom = bottom; self.side = side
    }
    func start() {
        let conf = WKWebViewConfiguration()
        conf.preferences.shouldPrintBackgrounds = true
        web = WKWebView(frame: NSRect(x: 0, y: 0, width: 612 - 2 * side, height: 792), configuration: conf)
        web.navigationDelegate = self
        window = NSWindow(contentRect: web.frame, styleMask: [.borderless], backing: .buffered, defer: false)
        window.isReleasedWhenClosed = false
        window.contentView = web
        window.setFrameOrigin(NSPoint(x: -6000, y: -6000))
        window.orderFrontRegardless()
        web.loadFileURL(input, allowingReadAccessTo: input.deletingLastPathComponent())
    }
    func webView(_ w: WKWebView, didFinish n: WKNavigation!) {
        // Espera a fuentes e imágenes antes de imprimir.
        w.callAsyncJavaScript("await document.fonts.ready; await Promise.all([...document.images].map(i => i.complete ? null : new Promise(r => { i.onload = i.onerror = r; }))); return document.images.length", arguments: [:], in: nil, in: .page) { _ in
            DispatchQueue.main.asyncAfter(deadline: .now() + 0.8) { self.printPDF() }
        }
    }
    func printPDF() {
        let info = NSPrintInfo()
        info.paperSize = NSSize(width: 612, height: 792) // Carta
        info.topMargin = top; info.bottomMargin = bottom; info.leftMargin = side; info.rightMargin = side
        info.horizontalPagination = .fit
        info.verticalPagination = .automatic
        info.isHorizontallyCentered = false
        info.isVerticallyCentered = false
        info.jobDisposition = .save
        info.dictionary()[NSPrintInfo.AttributeKey.jobSavingURL] = output
        let op = web.printOperation(with: info)
        op.showsPrintPanel = false
        op.showsProgressPanel = false
        op.view?.frame = NSRect(x: 0, y: 0, width: 612, height: 792)
        op.runModal(for: window, delegate: self, didRun: #selector(done(_:success:contextInfo:)), contextInfo: nil)
    }
    @objc func done(_ op: NSPrintOperation, success: Bool, contextInfo: UnsafeMutableRawPointer?) {
        print(success ? "ok \(output.lastPathComponent)" : "ERROR al generar \(output.lastPathComponent)")
        exit(success ? 0 : 1)
    }
}

func pageTexts(_ path: String) {
    guard let doc = PDFDocument(url: URL(fileURLWithPath: path)) else { print("no se pudo abrir"); exit(1) }
    for i in 0..<doc.pageCount {
        print("=====PAGINA \(i + 1)=====")
        print(doc.page(at: i)?.string ?? "")
    }
}

func assemble(cover: String, body: String, out: String, title: String, outline: String?) {
    guard let c = CGPDFDocument(URL(fileURLWithPath: cover) as CFURL), let b = CGPDFDocument(URL(fileURLWithPath: body) as CFURL) else { print("no se pudieron abrir los PDF"); exit(1) }
    var box = CGRect(x: 0, y: 0, width: 612, height: 792)
    guard let ctx = CGContext(URL(fileURLWithPath: out) as CFURL, mediaBox: &box, [kCGPDFContextTitle: title, kCGPDFContextAuthor: "GT Consulting", kCGPDFContextCreator: "ServiPos Tickets"] as CFDictionary) else { exit(1) }
    func draw(_ doc: CGPDFDocument, _ n: Int) { if let p = doc.page(at: n) { ctx.drawPDFPage(p) } }
    for n in 1...c.numberOfPages { ctx.beginPDFPage(nil); draw(c, n); ctx.endPDFPage() }
    let total = b.numberOfPages
    let font = CTFontCreateWithName("Helvetica" as CFString, 8.5, nil)
    let bold = CTFontCreateWithName("Helvetica-Bold" as CFString, 8.5, nil)
    let gray = CGColor(red: 0.37, green: 0.43, blue: 0.56, alpha: 1)
    let navy = CGColor(red: 0, green: 0.098, blue: 0.298, alpha: 1)
    func text(_ s: String, _ f: CTFont, _ color: CGColor, x: CGFloat, y: CGFloat, right: Bool = false) {
        let line = CTLineCreateWithAttributedString(NSAttributedString(string: s, attributes: [.font: f, .foregroundColor: NSColor(cgColor: color)!]))
        let w = CGFloat(CTLineGetTypographicBounds(line, nil, nil, nil))
        ctx.textPosition = CGPoint(x: right ? x - w : x, y: y)
        CTLineDraw(line, ctx)
    }
    for n in 1...total {
        ctx.beginPDFPage(nil)
        draw(b, n)
        // Cabecera: cliente y desarrollador.
        text("ServiPos 2019, C.A.", bold, gray, x: 54, y: 760)
        text("Desarrollado por GT Consulting", font, gray, x: 558, y: 760, right: true)
        // Pie: línea, título del documento y número de página.
        ctx.setStrokeColor(CGColor(red: 0.86, green: 0.89, blue: 0.93, alpha: 1)); ctx.setLineWidth(0.7)
        ctx.move(to: CGPoint(x: 54, y: 44)); ctx.addLine(to: CGPoint(x: 558, y: 44)); ctx.strokePath()
        text(title, font, gray, x: 54, y: 30)
        text("Página \(n) de \(total)", bold, navy, x: 558, y: 30, right: true)
        ctx.endPDFPage()
    }
    ctx.closePDF()
    if let outline, let rows = try? String(contentsOfFile: outline, encoding: .utf8), let doc = PDFDocument(url: URL(fileURLWithPath: out)) {
        let root = PDFOutline()
        var chapter: PDFOutline?
        for row in rows.split(separator: "\n") {
            let f = row.split(separator: "\t", maxSplits: 2).map(String.init)
            guard f.count == 3, let n = Int(f[1]), let page = doc.page(at: c.numberOfPages + n - 1) else { continue }
            let item = PDFOutline()
            item.label = f[2]
            item.destination = PDFDestination(page: page, at: CGPoint(x: 0, y: 792))
            if f[0] == "1" || chapter == nil {
                root.insertChild(item, at: root.numberOfChildren); chapter = item; item.isOpen = false
            } else {
                chapter!.insertChild(item, at: chapter!.numberOfChildren)
            }
        }
        doc.outlineRoot = root
        doc.write(to: URL(fileURLWithPath: out))
    }
    print("ok \(URL(fileURLWithPath: out).lastPathComponent): \(c.numberOfPages + total) páginas")
}

/// Exporta una página a PNG, para revisar el resultado.
func png(_ path: String, _ pageNumber: Int, _ out: String, _ scale: CGFloat) {
    guard let doc = CGPDFDocument(URL(fileURLWithPath: path) as CFURL), let page = doc.page(at: pageNumber) else { print("página no encontrada"); exit(1) }
    let box = page.getBoxRect(.mediaBox)
    let w = Int(box.width * scale), h = Int(box.height * scale)
    guard let ctx = CGContext(data: nil, width: w, height: h, bitsPerComponent: 8, bytesPerRow: 0, space: CGColorSpaceCreateDeviceRGB(), bitmapInfo: CGImageAlphaInfo.premultipliedLast.rawValue) else { exit(1) }
    ctx.setFillColor(CGColor(red: 1, green: 1, blue: 1, alpha: 1)); ctx.fill(CGRect(x: 0, y: 0, width: w, height: h))
    ctx.scaleBy(x: scale, y: scale)
    ctx.drawPDFPage(page)
    guard let image = ctx.makeImage(), let data = NSBitmapImageRep(cgImage: image).representation(using: .png, properties: [:]) else { exit(1) }
    try? data.write(to: URL(fileURLWithPath: out))
    print("ok \(out) (\(doc.numberOfPages) páginas en total)")
}

/// Varias páginas en una sola imagen (4 por fila), para revisar la maquetación de un vistazo.
func sheet(_ path: String, _ first: Int, _ count: Int, _ out: String, _ scale: CGFloat) {
    guard let doc = CGPDFDocument(URL(fileURLWithPath: path) as CFURL) else { exit(1) }
    let last = min(doc.numberOfPages, first + count - 1), cols = 4
    let rows = Int(ceil(Double(last - first + 1) / Double(cols)))
    let pw = 612 * scale, ph = 792 * scale, gap: CGFloat = 6
    let w = Int(CGFloat(cols) * (pw + gap) + gap), h = Int(CGFloat(rows) * (ph + gap) + gap)
    guard let ctx = CGContext(data: nil, width: w, height: h, bitsPerComponent: 8, bytesPerRow: 0, space: CGColorSpaceCreateDeviceRGB(), bitmapInfo: CGImageAlphaInfo.premultipliedLast.rawValue) else { exit(1) }
    ctx.setFillColor(CGColor(red: 0.45, green: 0.45, blue: 0.5, alpha: 1)); ctx.fill(CGRect(x: 0, y: 0, width: w, height: h))
    for n in first...last {
        let i = n - first, x = gap + CGFloat(i % cols) * (pw + gap), y = CGFloat(h) - gap - ph - CGFloat(i / cols) * (ph + gap)
        ctx.saveGState()
        ctx.translateBy(x: x, y: y); ctx.scaleBy(x: scale, y: scale)
        ctx.setFillColor(CGColor(red: 1, green: 1, blue: 1, alpha: 1)); ctx.fill(CGRect(x: 0, y: 0, width: 612, height: 792))
        if let page = doc.page(at: n) { ctx.drawPDFPage(page) }
        ctx.restoreGState()
    }
    guard let image = ctx.makeImage(), let data = NSBitmapImageRep(cgImage: image).representation(using: .png, properties: [:]) else { exit(1) }
    try? data.write(to: URL(fileURLWithPath: out))
    print("ok \(out): páginas \(first)–\(last)")
}

let a = CommandLine.arguments
setbuf(stdout, nil)
switch a.count > 1 ? a[1] : "" {
case "paginas": pageTexts(a[2])
case "png": png(a[2], Int(a[3])!, a[4], a.count > 5 ? CGFloat(Double(a[5])!) : 1.4)
case "hoja": sheet(a[2], Int(a[3])!, Int(a[4])!, a[5], a.count > 6 ? CGFloat(Double(a[6])!) : 0.6)
case "armar": assemble(cover: a[2], body: a[3], out: a[4], title: a[5], outline: a.count > 6 ? a[6] : nil)
case "render":
    let app = NSApplication.shared
    app.setActivationPolicy(.accessory)
    let p = Printer(input: URL(fileURLWithPath: a[2]), output: URL(fileURLWithPath: a[3]), top: CGFloat(Double(a[4])!), bottom: CGFloat(Double(a[5])!), side: CGFloat(Double(a[6])!))
    DispatchQueue.main.async { p.start() }
    app.run()
default: print("uso: pdf render|paginas|armar …"); exit(2)
}
