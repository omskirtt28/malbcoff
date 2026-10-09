(() => {
  'use strict';
  const modal=document.getElementById('inventoryVoidModal');
  if(!modal)return;
  const form=document.getElementById('inventoryVoidForm');
  let submitting=false;
  const close=()=>{if(submitting)return;modal.hidden=true;document.body.classList.remove('modal-open');document.getElementById('reviewInventoryVoid')?.focus();};
  document.getElementById('reviewInventoryVoid').addEventListener('click',()=>{
    const selected=[...document.querySelectorAll('[data-inventory-void]:checked')];
    const message=document.getElementById('inventoryVoidSelectionMessage');
    if(!selected.length || selected.length>100){message.textContent='Select 1 to 100 outgoing records first.';return;}
    message.textContent='';
    const ids=document.getElementById('inventoryVoidIds'),review=document.getElementById('inventoryVoidReview');
    ids.replaceChildren();review.replaceChildren();
    for(const checkbox of selected){
      const input=document.createElement('input');input.type='hidden';input.name='movement_ids[]';input.value=checkbox.value;ids.appendChild(input);
      const line=document.createElement('li');line.textContent=checkbox.dataset.voidSummary;review.appendChild(line);
    }
    form.querySelector('[name="reason"]').value='';
    modal.hidden=false;document.body.classList.add('modal-open');form.querySelector('[name="reason"]').focus();
  });
  modal.querySelectorAll('[data-void-close]').forEach(button=>button.addEventListener('click',close));
  document.addEventListener('keydown',event=>{if(event.key==='Escape'&&!modal.hidden)close();});
  form.addEventListener('submit',event=>{
    if(submitting){event.preventDefault();return;}
    if(!form.checkValidity()){event.preventDefault();form.reportValidity();return;}
    submitting=true;
    const button=form.querySelector('[type="submit"]');button.disabled=true;button.textContent='Voiding & restoring…';
  });
})();
