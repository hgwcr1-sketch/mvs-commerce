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
  const html=fs.readFileSync(process.env.COBROS_LOGIN_HTML || path.join(temp,'cobros-login-rendered.html'),'utf8')
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
  await send('Page.enable');await send('Runtime.enable');
  for(const width of [360,768,1280]){
    await send('Emulation.setDeviceMetricsOverride',{width,height:900,deviceScaleFactor:1,mobile:false});
    await send('Page.navigate',{url:'http://127.0.0.1:'+server.address().port+'/'});
    for(let i=0;i<100;i++){if(await evaluate("document.readyState === 'complete' && !!document.getElementById('email')"))break;await pause(50);}
    const state=await evaluate(`(()=>{
      const card=document.querySelector('main').getBoundingClientRect();
      const form=document.querySelector('form');
      const logo=document.querySelector('.cobros-login-logo');
      return {overflow:document.documentElement.scrollWidth>innerWidth,
        centered:Math.abs(card.x+card.width/2-document.documentElement.clientWidth/2)<2,
        top:card.top, logoLoaded:logo.naturalWidth>0, logoRatio:logo.width/logo.height,
        title:document.querySelector('h1').textContent,method:form.method,action:new URL(form.action).pathname,
        csrf:!!form.querySelector('input[name="_token"]'),
        targets:[...document.querySelectorAll('.cobros-login-field input,button,.cobros-login-remember')].map(e=>({height:e.getBoundingClientRect().height,width:e.getBoundingClientRect().width})),
        font:getComputedStyle(document.getElementById('email')).fontSize,
        emailAutocomplete:document.getElementById('email').autocomplete,
        passwordAutocomplete:document.getElementById('password').autocomplete,
        passwordType:document.getElementById('password').type,
        valid:form.checkValidity(),commerce:document.body.textContent.includes('MVS Commerce')};
    })()`);
    assert.equal(state.overflow,false);assert.equal(state.centered,true);assert.ok(state.top>=0);
    assert.equal(state.logoLoaded,true);assert.equal(state.logoRatio,1);assert.equal(state.title,'Bienvenido a MVS CobrOS');
    assert.equal(state.method,'post');assert.equal(state.action,'/cobros/login');assert.equal(state.csrf,true);
    state.targets.forEach(t=>assert.ok(t.height>=44&&t.width>=44));
    assert.equal(state.font,'16px');assert.equal(state.emailAutocomplete,'username');
    assert.equal(state.passwordAutocomplete,'current-password');assert.equal(state.passwordType,'password');
    assert.equal(state.valid,false);assert.equal(state.commerce,false);
    await evaluate("document.getElementById('email').focus()");
    await send('Input.insertText',{text:'browser@example.test'});
    await send('Input.dispatchKeyEvent',{type:'keyDown',key:'Tab',code:'Tab',windowsVirtualKeyCode:9});
    await send('Input.dispatchKeyEvent',{type:'keyUp',key:'Tab',code:'Tab',windowsVirtualKeyCode:9});
    assert.equal(await evaluate('document.activeElement.id'),'password');
    await send('Input.insertText',{text:'OnlyBrowserFixture123'});
    assert.equal(await evaluate("document.querySelector('form').checkValidity()"),true);
    await evaluate("document.getElementById('remember').click()");
    assert.equal(await evaluate("document.getElementById('remember').checked"),true);
    const screenshot=await send('Page.captureScreenshot',{format:'png'});
    fs.writeFileSync(path.join(temp,'cobros-login-'+width+'.png'),Buffer.from(screenshot.data,'base64'));
    if(width===360){
      // Reduced viewport approximates keyboard space; physical keyboards remain a manual check.
      await send('Emulation.setDeviceMetricsOverride',{width,height:420,deviceScaleFactor:1,mobile:false});
      await evaluate("document.querySelector('button[type=submit]').scrollIntoView({block:'end'})");
      const reduced=await evaluate("({overflow:document.documentElement.scrollWidth>innerWidth,bottom:document.querySelector('button[type=submit]').getBoundingClientRect().bottom})");
      assert.equal(reduced.overflow,false);assert.ok(reduced.bottom<=420);
    }
    console.log(JSON.stringify({width,result:'PASS',checks:'centered; no overflow; 44px targets; logo; email/password keyboard entry; remember; native form validation; isolated branding'}));
  }
  assert.deepEqual(errors,[],'Browser runtime errors');
  await send('Browser.close').catch(()=>{});
})().catch(e=>{console.error(e);process.exitCode=1;}).finally(()=>{if(ws)ws.close();if(browser)browser.kill();if(server)server.close();});
