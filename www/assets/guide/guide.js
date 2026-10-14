(function(){
 function revealHash(){
  var key; try{key=decodeURIComponent(location.hash.slice(1));}catch(e){return;}
  if(!key)return;
  var target=document.getElementById(key);if(!target)return;
  var parent=target.parentElement;
  while(parent){if(parent.tagName==='DETAILS')parent.open=true;parent=parent.parentElement;}
  requestAnimationFrame(function(){target.scrollIntoView({block:'start'});});
 }
 window.addEventListener('hashchange',revealHash);revealHash();
 if('IntersectionObserver' in window){
  var links=document.querySelectorAll('.guide-toc nav a');
  var observer=new IntersectionObserver(function(entries){entries.forEach(function(entry){if(entry.isIntersecting){links.forEach(function(link){var on=link.hash==='#'+entry.target.id;link.classList.toggle('active',on);if(on)link.setAttribute('aria-current','location');else link.removeAttribute('aria-current');});}});},{rootMargin:'-20% 0px -55% 0px'});
  document.querySelectorAll('.guide-section').forEach(function(section){observer.observe(section);});
 }
})();
