const assert=require('node:assert/strict'),vm=require('node:vm'),fs=require('node:fs');
const source=fs.readFileSync(process.argv[2],'utf8');
function link(href){return {innerHTML:'old',getAttribute:()=>href,remove(){this.parent.links=this.parent.links.filter(x=>x!==this)}}}
function menu(links=[]){const m={links,querySelectorAll(){return this.links},appendChild(x){x.parent=this;x.getAttribute=()=>x.href;this.links.push(x)}};links.forEach(x=>x.parent=m);return m}
const applications=menu([link('/admin/addons/radius/'),link('/other')]);
const provider=menu([link('/admin/addons/radius/'),link('/admin/addons/radius/')]);
let visible=true,observer;
const context={document:{readyState:'complete',body:{},querySelectorAll:()=>[],querySelector:()=>({}),getElementById:id=>visible?(id==='menu_provedor'?provider:applications):null,createElement:()=>({})},window:{setTimeout(){}},MutationObserver:class{constructor(fn){observer=fn}observe(){}disconnect(){}}};
vm.runInNewContext(source,context);vm.runInNewContext(source,context);
assert.equal(provider.links.length,1);assert.match(provider.links[0].innerHTML,/LOG RADIUS/);assert.equal(applications.links.length,1);
provider.links=[];visible=false;vm.runInNewContext(source,context);visible=true;observer();assert.equal(provider.links.length,1);
console.log('PASS independent provider shortcut, duplicate cleanup and delayed native menu');
