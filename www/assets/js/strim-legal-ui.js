(function(){
  var box=document.createElement('aside');
  box.style.cssText='padding:14px;margin:12px;border:1px solid #777;border-radius:10px';
  box.innerHTML='<a href="/consumer.html">Споживачу: правила, повернення та звернення</a> · <a href="/auth/legal.php">Мої умови сервісу</a><p>Після поповнення для нових покупок натисніть «Старт». Пауза припиняє покупки; продажі відкритих позицій можуть тривати. Комісія — з кожного позитивного продажу, навіть за загального збитку портфеля.</p>';
  document.body.appendChild(box);
  // Existing accounts remain readable; only new payments/starts require current acceptance.
  fetch('/api/legal_status.php',{credentials:'same-origin'}).then(function(r){return r.json();}).then(function(j){
    if(j.ok && !j.accepted){
      var a=document.createElement('p');a.innerHTML='<strong>Перед новим запуском або оплатою <a href="/auth/legal.php">перегляньте й прийміть актуальні умови</a>.</strong>';box.prepend(a);
    }
  }).catch(function(){});
})();
