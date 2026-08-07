<?php declare(strict_types=1); ?>
<dialog class="modal" data-quick-modal>
  <form class="modal__inner" method="dialog" data-quick-form>
    <h2 class="modal__title" data-quick-title>Add</h2>
    <p class="field__hint" data-quick-hint></p>

    <div style="display:flex;flex-direction:column;gap:14px" data-quick-fields></div>

    <div class="modal__actions">
      <button class="btn" type="button" value="cancel" data-quick-cancel>Cancel</button>
      <button class="btn btn--primary" type="submit" data-quick-submit>Save</button>
    </div>
  </form>
</dialog>
