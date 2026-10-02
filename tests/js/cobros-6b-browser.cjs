// Rendered from exclusive PHPUnit fixtures; this server cannot register payments.
const fs = require('node:fs');
const path = require('node:path');
const http = require('node:http');
const {spawn} = require('node:child_process');
const assert = require('node:assert/strict');
const temp = require('node:os').tmpdir();
const root = path.resolve(__dirname, '../..');
const profile = fs.mkdtempSync(path.join(temp, 'cobros-6b-chrome-'));
const pause = ms => new Promise(resolve => setTimeout(resolve, ms));
let browser, server, ws;
(async () => {
  let posts = 0;
  server = http.createServer((req, res) => {
    if (req.method !== 'GET') { posts++; res.writeHead(503); res.end('Test server: no financial writes'); return; }
    const pathname = new URL(req.url, 'http://localhost').pathname;
    const name = pathname.split('/').pop();
    if (['form', 'preview', 'receipt'].includes(name)) {
      const html = fs.readFileSync(path.join(temp, `cobros-6b-${name}.html`), 'utf8').replace(/https?:\/\/[^/"'\s]+\//g, '/');
      res.setHeader('Content-Type', 'text/html; charset=utf-8'); res.end(html); return;
    }
    const file = path.resolve(root, 'public', '.' + pathname);
    if (!file.startsWith(path.join(root, 'public') + path.sep) || !fs.existsSync(file) || !fs.statSync(file).isFile()) { res.writeHead(404); res.end(); return; }
    res.setHeader('Content-Type', {'.css':'text/css', '.js':'application/javascript', '.json':'application/json', '.png':'image/png'}[path.extname(file)] || 'application/octet-stream');
    res.end(fs.readFileSync(file));
  });
  await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
  browser = spawn(process.env.COBROS_CHROME_PATH || 'C:/Program Files/Google/Chrome/Application/chrome.exe', ['--headless=new', '--disable-gpu', '--no-first-run', '--no-default-browser-check', '--remote-debugging-port=0', '--user-data-dir=' + profile, 'about:blank'], {windowsHide:true, stdio:'ignore'});
  const portFile = path.join(profile, 'DevToolsActivePort');
  for (let i=0; i<200 && !fs.existsSync(portFile); i++) await pause(100);
  assert.ok(fs.existsSync(portFile), 'Chrome debugging ready');
  const port = fs.readFileSync(portFile, 'utf8').split('\n')[0];
  const pages = await (await fetch(`http://127.0.0.1:${port}/json/list`)).json();
  ws = new WebSocket(pages.find(page => page.type === 'page').webSocketDebuggerUrl);
  await new Promise((resolve,reject) => { ws.addEventListener('open',resolve,{once:true}); ws.addEventListener('error',reject,{once:true}); });
  let sequence=0; const pending=new Map();
  ws.addEventListener('message', event => {
    const data=JSON.parse(event.data); const entry=pending.get(data.id);
    if (entry) { clearTimeout(entry.timer); pending.delete(data.id); data.error ? entry.reject(new Error(JSON.stringify(data.error))) : entry.resolve(data.result); }
  });
  const send=(method,params={}) => new Promise((resolve,reject) => {
    const id=++sequence; const timer=setTimeout(() => {pending.delete(id); reject(new Error('CDP timeout '+method));},15000);
    pending.set(id,{resolve,reject,timer}); ws.send(JSON.stringify({id,method,params}));
  });
  const evaluate=async expression => {
    const data=await send('Runtime.evaluate',{expression,returnByValue:true,awaitPromise:true});
    if(data.exceptionDetails) throw new Error(JSON.stringify(data.exceptionDetails));
    return data.result.value;
  };
  await send('Page.enable'); await send('Network.enable');
  const origin=`http://127.0.0.1:${server.address().port}`;
  const findings=[];
  for (const width of [360,768,1280]) {
    await send('Emulation.setDeviceMetricsOverride',{width,height:900,deviceScaleFactor:1,mobile:false});
    for (const name of ['form','preview','receipt']) {
      await send('Page.navigate',{url:`${origin}/cobros/${name}`});
      for(let i=0;i<100;i++) { if(await evaluate(`document.readyState === 'complete' && location.pathname === '/cobros/${name}'`)) break; await pause(50); }
      await pause(200);
      const metrics=await evaluate(`({width:document.documentElement.clientWidth,scroll:document.documentElement.scrollWidth,smallTargets:[...document.querySelectorAll('button,input:not([type=hidden]),select,a')].filter(e=>e.getBoundingClientRect().height>0 && e.getBoundingClientRect().height<40).map(e=>e.textContent.trim().slice(0,40))})`);
      findings.push({width,page:name,...metrics});
      const shot=await send('Page.captureScreenshot',{format:'png'});
      fs.writeFileSync(path.join(temp,`cobros-6b-${name}-${width}.png`),Buffer.from(shot.data,'base64'));
    }
  }
  console.log(JSON.stringify({responsive:findings}));
  await send('Page.navigate',{url:origin+'/cobros/preview'});
  await pause(500);
  const registration=await evaluate(`(async()=>{const r=await navigator.serviceWorker.ready;return {scope:r.scope,active:r.active?.scriptURL};})()`);
  await send('Page.reload'); await pause(500);
  assert.ok(await evaluate('!!navigator.serviceWorker.controller'),'SW controls page');
  await send('Network.emulateNetworkConditions',{offline:true,latency:0,downloadThroughput:0,uploadThroughput:0});
  const payment=await evaluate(`fetch('/cobros/test-payment',{method:'POST',body:'confirm=1'}).then(r=>({ok:r.ok,status:r.status})).catch(()=>({ok:false,networkError:true}))`);
  assert.equal(payment.ok,false); assert.equal(posts,0,'Offline POST never reaches server');
  const fallback=await evaluate(`fetch('/cobros/no-network',{headers:{accept:'text/html'}}).then(async r=>({status:r.status,text:await r.text()}))`);
  assert.equal(fallback.status,503); assert.ok(fallback.text.includes('Sin conexión'));
  const cachesSeen=await evaluate(`(async()=>{const out=[];for(const key of await caches.keys()){const cache=await caches.open(key);out.push({key,urls:(await cache.keys()).map(r=>r.url)});}return out;})()`);
  assert.ok(cachesSeen.every(c=>c.urls.every(url=>/\/(icons\/cobros\/|manifest-cobros.json)/.test(url))),'Only public assets cached');
  console.log(JSON.stringify({pwa:'PASS local SW only; device installation pending',registration,offlinePost:payment,offlineFallback:fallback.status,caches:cachesSeen}));
  assert.ok(findings.every(f=>f.scroll<=f.width),'Horizontal overflow');
})().catch(error=>{console.error(error);process.exitCode=1;}).finally(()=>{ws?.close();browser?.kill();server?.close();});
