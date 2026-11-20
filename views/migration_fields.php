<?php
/** Accessible field warnings shared by the migration pilot forms. */
function migrationField(string $name, string $label, array $values, array $errors, string $type='text', bool $required=true, bool $readonly=false, array $options=[]): void
{
    $error=$errors[$name]??''; $id='migration_'.preg_replace('/[^a-zA-Z0-9_-]+/','_',$name);
    ?>
    <div class="col-md-6 mb-3">
        <label class="form-label<?= $name==='postcode'?' fw-bold':'' ?>" for="<?= h($id) ?>"><?= h($label) ?><?= $required?' <span class="text-danger" aria-hidden="true">*</span>':'' ?></label>
        <?php if($error): ?><div id="<?= h($id) ?>_error" class="text-danger small mb-1"><?= h($error) ?></div><?php endif; ?>
        <?php if($type==='textarea'): ?>
            <textarea class="form-control <?= $error?'is-invalid':'' ?>" id="<?= h($id) ?>" name="<?= h($name) ?>" rows="4" <?= $required?'required':'' ?> <?= $error?'aria-invalid="true" aria-describedby="'.h($id).'_error"':'' ?>><?= h($values[$name]??'') ?></textarea>
        <?php elseif($type==='select'): ?>
            <select class="form-select <?= $error?'is-invalid':'' ?>" id="<?= h($id) ?>" name="<?= h($name) ?>" <?= $required?'required':'' ?> <?= $error?'aria-invalid="true" aria-describedby="'.h($id).'_error"':'' ?>>
                <option value="">Select…</option><?php foreach($options as $value=>$optionLabel): ?><option value="<?= h($value) ?>" <?= (string)($values[$name]??'')===(string)$value?'selected':'' ?>><?= h($optionLabel) ?></option><?php endforeach; ?>
            </select>
        <?php elseif($type==='classification'): ?>
            <select class="form-select <?= $error?'is-invalid':'' ?>" id="<?= h($id) ?>" name="<?= h($name) ?>" required <?= $error?'aria-invalid="true" aria-describedby="'.h($id).'_error"':'' ?>>
                <option value="">Select…</option><?php foreach(['Junior','Senior'] as $option): ?><option <?= ($values[$name]??'')===$option?'selected':'' ?>><?= h($option) ?></option><?php endforeach; ?>
            </select>
        <?php else: ?>
            <?php if($type==='password'): ?><div class="input-group"><?php endif; ?>
            <input class="form-control <?= $error?'is-invalid':'' ?>" type="<?= h($type) ?>" id="<?= h($id) ?>" name="<?= h($name) ?>" value="<?= $type==='password'?'':h($values[$name]??'') ?>" <?= $required?'required':'' ?> <?= $readonly?'readonly':'' ?> <?= str_starts_with($name,'horses[')?'autocomplete="off"':'' ?> <?= $type==='password'?'autocomplete="'.($name==='current_password'?'current-password':'new-password').'"':'' ?> <?= $type==='date'?'max="'.date('Y-m-d').'"':'' ?> <?= $error?'aria-invalid="true" aria-describedby="'.h($id).'_error"':'' ?>>
            <?php if($type==='password'): ?><button class="btn btn-outline-secondary" type="button" data-migration-password-toggle data-password-label="<?= h(strtolower($label)) ?>" aria-controls="<?= h($id) ?>" aria-pressed="false" aria-label="Show <?= h(strtolower($label)) ?>"><i class="fa-solid fa-eye" aria-hidden="true"></i></button></div><?php endif; ?>
        <?php endif; ?>
    </div>
    <?php
}
function migrationPreferenceFields(array $values, string $prefix): void
{
    $field='ride_notice_opt_in'; ?>
    <div class="form-check mb-2"><input class="form-check-input" type="checkbox" name="<?= h($field) ?>" value="1" id="<?= h($prefix.'_'.$field) ?>" <?= !empty($values[$field])?'checked':'' ?>><label class="form-check-label" for="<?= h($prefix.'_'.$field) ?>">Ride Reminders and Updates</label></div>
    <?php
}

function migrationRenderHorse(array $horse,array $errors,array $qualifications):void
{
    $id=(int)$horse['id'];$values=[];
    foreach(migrationHorseFields() as $field=>$label)$values['horses['.$id.']['.$field.']']=$horse[$field]??'';
    $heights=migrationHorseHeights();
    if(!empty($horse['height_cm']) && !isset($heights[(int)$horse['height_cm']]))$heights[(int)$horse['height_cm']]=$horse['height_cm'].' cm';
    foreach(migrationHorseFields() as $field=>$label){
        $name='horses['.$id.']['.$field.']';
        $options=match($field){'sex'=>['Mare'=>'Mare','Gelding'=>'Gelding','Stallion'=>'Stallion'],'height_cm'=>$heights,'qualification_id'=>$qualifications,default=>[]};
        migrationField($name,$label,$values,$errors,$options?'select':($field==='year_of_birth'?'number':'text'),true,false,$options);
    }
}
