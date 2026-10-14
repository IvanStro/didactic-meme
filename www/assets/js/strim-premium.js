(function(){
 var menu=document.querySelector('.pr-menu');
 if(menu){document.addEventListener('click',function(e){if(menu.open&&!menu.contains(e.target))menu.open=false;});document.addEventListener('keydown',function(e){if(e.key==='Escape'&&menu.open){menu.open=false;menu.querySelector('summary').focus();}});}
 var search=document.getElementById('faq-search');
 if(search){var items=Array.from(document.querySelectorAll('.pr-faq-item'));search.addEventListener('input',function(){var q=search.value.trim().toLocaleLowerCase('uk');var count=0;items.forEach(function(item){item.hidden=q!==''&&!item.textContent.toLocaleLowerCase('uk').includes(q);if(!item.hidden)count++;});document.getElementById('faq-count').textContent='Знайдено відповідей: '+count;document.getElementById('faq-empty').hidden=count>0;});}
 function reveal(){var id;try{id=decodeURIComponent(location.hash.slice(1));}catch(e){return;}if(!id)return;var target=document.getElementById(id);if(!target)return;if(target.matches('.pr-faq-item')){target.hidden=false;target.open=true;}requestAnimationFrame(function(){target.scrollIntoView({block:'start'});});}
 window.addEventListener('hashchange',reveal);reveal();
})();
