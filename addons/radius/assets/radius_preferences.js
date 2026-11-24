(function(){
 window.mkaRadiusPreference=function(input,feedback,paint){
  var saved=false,csrf='',saving=false,closed=false;
  input.disabled=true;
  function accept(data){if(!data.ok)throw new Error();csrf=data.csrf;saved=data.hide_disabled===true;input.checked=saved;input.disabled=false;paint();}
  function result(response){if(!response.ok)throw new Error();return response.json();}
  function load(){
   if(saving||closed||document.hidden)return;
   return fetch('/admin/addons/radius/preferences.php',{credentials:'same-origin',cache:'no-store'})
    .then(result).then(accept).catch(function(){if(!csrf){input.disabled=true;feedback.textContent='N\u00e3o foi poss\u00edvel carregar a configura\u00e7\u00e3o do servidor.';}});
  }
  input.onchange=function(){
   if(saving||!csrf)return;
   saving=true;input.disabled=true;feedback.textContent='Salvando...';
   fetch('/admin/addons/radius/preferences.php',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json'},body:JSON.stringify({hide_disabled:input.checked,csrf:csrf})})
    .then(result).then(function(data){accept(data);feedback.textContent='Salvo para todos os usu\u00e1rios e navegadores.';})
    .catch(function(){input.checked=saved;input.disabled=false;paint();feedback.textContent='N\u00e3o foi poss\u00edvel salvar. Tente novamente.';})
    .then(function(){saving=false;});
  };
  load();var timer=setInterval(load,15000);
  window.addEventListener('pagehide',function(){closed=true;clearInterval(timer);});
 };
}());
