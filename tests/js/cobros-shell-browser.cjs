const fs = require('node:fs');
const path = require('node:path');
const http = require('node:http');
const {spawn} = require('node:child_process');
const assert = require('node:assert/strict');
const root = path.resolve(__dirname, '../..');
const temp = require('node:os').tmpdir();
const profile = fs.mkdtempSync(path.join(temp,'cobros-chrome-'));
const pause = ms => new Promise(r=>setTimeout(r,ms));
let browser, server, ws;
(async()=>{
  const html=fs.readFileSync(process.env.COBROS_SHELL_HTML || path.join(temp,'cobros-shell-rendered.html'),'utf8')
    .replace(/https?:\/\/[^/"'\s]+\//g,'/');
  server=http.createServer((req,res)=>{
    const pathname=new URL(req.url,'http://localhost').pathname;
    if(pathname==='/'){res.setHeader('Content-Type','text/html; charset=utf-8');res.end(html);return;}
    const file=path.resolve(root,'public','.'+pathname);
    if(!file.startsWith(path.resolve(root,'public')+path.sep)||!fs.existsSync(file)||!fs.statSync(file).isFile()){res.writeHead(404);res.end();return;}
    const type={'.css':'text/css','.js':'application/javascript','.png':'image/png','.json':'application/json'}[path.extname(file)]||'application/octet-stream';
    res.setHeader('Content-Type',type);res.end(fs.readFileSync(file));
  });
  await new Promise(r=>server.listen(0,'127.0.0.1',r));
  browser=spawn(process.env.COBROS_CHROME_PATH || 'C:/Program Files/Google/Chrome/Application/chrome.exe',[
    '--headless=new','--disable-gpu','--no-first-run','--no-default-browser-check',
    '--remote-debugging-port=0','--user-data-dir='+profile,'about:blank'
  ],{windowsHide:true,stdio:'ignore'});
  const portFile=path.join(profile,'DevToolsActivePort');
  for(let i=0;i<300&&!fs.existsSync(portFile);i++) await pause(100);
  assert.ok(fs.existsSync(portFile),'Chrome debugging ready');
  const port=fs.readFileSync(portFile,'utf8').split('\n')[0];
  const pages=await (await fetch('http://127.0.0.1:'+port+'/json/list')).json();
  ws=new WebSocket(pages.find(p=>p.type==='page').webSocketDebuggerUrl);
  await new Promise((resolve,reject)=>{ws.addEventListener('open',resolve,{once:true});ws.addEventListener('error',reject,{once:true});});
  let sequence=0; const pending=new Map(); const errors=[];
  ws.addEventListener('message',event=>{
    const message=JSON.parse(event.data);
    if(message.method==='Runtime.exceptionThrown') errors.push(message.params.exceptionDetails.text);
    if(pending.has(message.id)){const {resolve,reject,timer}=pending.get(message.id);clearTimeout(timer);pending.delete(message.id);message.error?reject(new Error(JSON.stringify(message.error))):resolve(message.result);}
  });
  const send=(method,params={})=>new Promise((resolve,reject)=>{
    const id=++sequence;
    const timer=setTimeout(()=>{pending.delete(id);reject(new Error('CDP timeout '+method));},15000);
    pending.set(id,{resolve,reject,timer});ws.send(JSON.stringify({id,method,params}));
  });
  const evaluate=async expression=>{
    const result=await send('Runtime.evaluate',{expression,returnByValue:true});
    if(result.exceptionDetails) throw new Error(JSON.stringify(result.exceptionDetails));
    return result.result.value;
  };
  const click=async(x,y)=>{await send('Input.dispatchMouseEvent',{type:'mousePressed',x,y,button:'left',clickCount:1});await send('Input.dispatchMouseEvent',{type:'mouseReleased',x,y,button:'left',clickCount:1});};
  const clickElement=async id=>{const point=await evaluate(`(()=>{const r=document.getElementById('${id}').getBoundingClientRect();return {x:r.x+r.width/2,y:r.y+r.height/2}})()`);await click(point.x,point.y);};
  const isOpen=()=>evaluate("document.getElementById('cobros-more-panel').open");
  const settle=async expected=>{for(let i=0;i<30;i++){if(await isOpen()===expected)return;await pause(30);}throw new Error('Panel state mismatch');};
  await send('Page.enable');await send('Runtime.enable');
  for(const width of [360,768,1280]){
    await send('Emulation.setDeviceMetricsOverride',{width,height:900,deviceScaleFactor:1,mobile:false});
    await send('Page.navigate',{url:'http://127.0.0.1:'+server.address().port+'/'});
    for(let i=0;i<100;i++){if(await evaluate("document.readyState === 'complete' && !!document.getElementById('cobros-more')"))break;await pause(50);}
    const initial=await evaluate(`(()=>{
      const panel=document.getElementById('cobros-more-panel');
      const bar=document.querySelector('.cobros-navigation');
      const rect=bar.getBoundingClientRect();
      return {clientWidth:document.documentElement.clientWidth,open:panel.open,display:getComputedStyle(panel).display,overflow:document.documentElement.scrollWidth>innerWidth,
        offset:document.querySelector('.cobros-workspace').getBoundingClientRect().left,
        mainWidth:document.querySelector('.cobros-main').getBoundingClientRect().width,
        barWidth:rect.width,bottom:rect.bottom,
        targets:[...bar.children].map(e=>({height:e.getBoundingClientRect().height,width:e.getBoundingClientRect().width,direction:getComputedStyle(e).flexDirection})),
        metrics:[...document.querySelectorAll('.cobros-metric>p')].map(e=>e.textContent.trim())};
    })()`);
    assert.equal(initial.open,false);assert.equal(initial.display,'none');assert.equal(initial.overflow,false);
    assert.equal(initial.offset,0);assert.equal(initial.mainWidth,initial.clientWidth);assert.equal(initial.barWidth,initial.clientWidth);assert.equal(initial.bottom,900);
    initial.targets.forEach(t=>{assert.ok(t.height>=44&&t.width>=44);assert.equal(t.direction,'column');});
    assert.deepEqual(initial.metrics,['₡0.00','₡0.00','₡0.00','0','0','0']);
    await clickElement('cobros-more');await settle(true);
    assert.equal(await evaluate("document.getElementById('cobros-more-panel').matches(':modal')"),true);
    assert.equal(await evaluate("document.getElementById('cobros-more').getAttribute('aria-expanded')"),'true');
    assert.equal(await evaluate("document.activeElement.id"),'cobros-more-close');
    await send('Input.dispatchKeyEvent',{type:'keyDown',key:'Escape',code:'Escape',windowsVirtualKeyCode:27});
    await send('Input.dispatchKeyEvent',{type:'keyUp',key:'Escape',code:'Escape',windowsVirtualKeyCode:27});
    await settle(false);await pause(30);
    assert.equal(await evaluate("document.activeElement.id"),'cobros-more');
    await clickElement('cobros-more');await settle(true);await clickElement('cobros-more-close');await settle(false);
    await clickElement('cobros-more');await settle(true);await click(4,100);await settle(false);await pause(30);
    assert.equal(await evaluate("document.documentElement.style.overflow"),'');
    assert.equal(await evaluate("document.getElementById('cobros-more').getAttribute('aria-expanded')"),'false');
    const screenshot=await send('Page.captureScreenshot',{format:'png'});
    fs.writeFileSync(path.join(temp,`cobros-shell-${width}.png`),Buffer.from(screenshot.data,'base64'));
    console.log(JSON.stringify({width,result:'PASS',checks:'horizontal bar; 44px targets; full width; no overflow; hidden initial panel; open; Escape; close button; backdrop; focus return; 3 currency metrics; integer counts'}));
  }
  assert.deepEqual(errors,[],'Browser runtime errors');
  await send('Browser.close').catch(()=>{});
})().catch(e=>{console.error(e);process.exitCode=1;}).finally(()=>{if(ws)ws.close();if(browser)browser.kill();if(server)server.close();});
