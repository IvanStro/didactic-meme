(function(){
  var KEY='strim-theme';
  var LEGACY='strim-landing-theme';
  var root=document.documentElement;
  var meta=document.querySelector('meta[name="theme-color"]');
  function themeMeta(c){ if(meta) meta.setAttribute('content', c); }
  function apply(t){
    root.setAttribute('data-theme', t);
    try{ localStorage.setItem(KEY, t); }catch(e){}
    themeMeta(t==='light' ? '#eef2f7' : '#050810');
    var btn=document.getElementById('theme-toggle');
    if(btn){
      btn.setAttribute('aria-label', t==='light' ? 'Увімкнути темну тему' : 'Увімкнути світлу тему');
      btn.setAttribute('title', t==='light' ? 'Темна тема' : 'Світла тема');
    }
  }
  var saved=null;
  try{
    saved=localStorage.getItem(KEY);
    if(!saved) saved=localStorage.getItem(LEGACY);
  }catch(e){}
  if(saved==='light' || saved==='dark') apply(saved);
  else apply('dark');
  var toggle=document.getElementById('theme-toggle');
  if(toggle){
    toggle.addEventListener('click', function(){
      apply(root.getAttribute('data-theme')==='light' ? 'dark' : 'light');
    });
  }

  var navBtn=document.getElementById('strimNavToggle');
  var pubNav=document.getElementById('strimPubNav');
  if(navBtn && pubNav){
    navBtn.addEventListener('click', function(ev){
      ev.preventDefault();
      var open=!document.body.classList.contains('strim-nav-open');
      document.body.classList.toggle('strim-nav-open', open);
      navBtn.setAttribute('aria-expanded', open ? 'true' : 'false');
    });
    pubNav.querySelectorAll('a').forEach(function(a){
      a.addEventListener('click', function(){
        document.body.classList.remove('strim-nav-open');
        navBtn.setAttribute('aria-expanded', 'false');
      });
    });
  }
})();
