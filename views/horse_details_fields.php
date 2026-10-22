<?php // Shared fields for logbook registration and editing an existing horse. ?>
	                                        <div class="col-12">
	                                            <label class="form-label fw-bold">Horse name</label>
	                                            <input class="form-control" name="name" required value="<?php echo h($horseDetails['name'] ?? ''); ?>">
	                                        </div>
	                                        <div class="col-12 col-md-6">
	                                            <label class="form-label fw-bold">Breed</label>
	                                            <input class="form-control" name="breed" <?php echo $horseDetailsRequired ? 'required' : ''; ?> value="<?php echo h($horseDetails['breed'] ?? ''); ?>">
	                                        </div>
	                                        <div class="col-12 col-md-6">
	                                            <label class="form-label fw-bold">Colour</label>
	                                            <input class="form-control" name="colour" <?php echo $horseDetailsRequired ? 'required' : ''; ?> value="<?php echo h($horseDetails['colour'] ?? ''); ?>">
	                                        </div>
	                                        <div class="col-12 col-md-6">
	                                            <label class="form-label fw-bold">Sex</label>
	                                            <select class="form-select" name="sex" <?php echo $horseDetailsRequired ? 'required' : ''; ?>>
	                                                <option value="">Select...</option>
	                                                <?php
	                                                $sexes = ['Mare', 'Gelding', 'Stallion'];
	                                                foreach ($sexes as $sexOpt):
	                                                ?>
	                                                    <option value="<?php echo h($sexOpt); ?>" <?php echo (($horseDetails['sex'] ?? '') === $sexOpt) ? 'selected' : ''; ?>><?php echo h($sexOpt); ?></option>
	                                                <?php endforeach; ?>
	                                            </select>
	                                        </div>
	                                        <div class="col-12 col-md-6">
	                                            <label class="form-label fw-bold">Height</label>
	                                            <select class="form-select" name="height_cm" <?php echo $horseDetailsRequired ? 'required' : ''; ?>>
	                                                <option value="">Select...</option>
	                                                <?php foreach ([102=>'10.0',112=>'11.0',122=>'12.0',127=>'12.2',132=>'13.0',137=>'13.2',142=>'14.0',145=>'14.1',147=>'14.2',150=>'14.3',152=>'15.0',155=>'15.1',157=>'15.2',160=>'15.3',163=>'16.0',165=>'16.1',168=>'16.2',170=>'16.3',173=>'17.0',175=>'17.1',178=>'17.2',180=>'17.3',183=>'18.0'] as $heightCm => $heightHands): ?>
	                                                    <option value="<?php echo $heightCm; ?>" <?php echo ((int)($horseDetails['height_cm'] ?? 0) === $heightCm) ? 'selected' : ''; ?>><?php echo $heightCm; ?> cm [<?php echo h($heightHands); ?> hh]</option>
	                                                <?php endforeach; ?>
	                                            </select>
	                                        </div>
	                                        <div class="col-12 col-md-6">
	                                            <label class="form-label fw-bold">Qualification</label>
	                                            <select class="form-select" name="qualification_id" <?php echo $horseDetailsRequired ? 'required' : ''; ?>>
	                                                <option value="">Select...</option>
	                                                <?php foreach ($horseQualifications as $hq): ?>
	                                                    <option value="<?php echo (int)($hq['id'] ?? 0); ?>" <?php echo ((int)($horseDetails['qualification_id'] ?? 0) === (int)($hq['id'] ?? 0)) ? 'selected' : ''; ?>>
	                                                        <?php echo h($hq['name'] ?? ''); ?>
	                                                    </option>
	                                                <?php endforeach; ?>
	                                            </select>
	                                        </div>
	                                        <div class="col-12 col-md-6">
	                                            <label class="form-label fw-bold">Year of birth</label>
	                                            <input class="form-control" type="number" name="year_of_birth" <?php echo $horseDetailsRequired ? 'required' : ''; ?> min="1000" max="<?php echo date('Y'); ?>" value="<?php echo h($horseDetails['year_of_birth'] ?? ''); ?>">
	                                        </div>
	                                        <div class="col-12 col-md-6">
	                                            <label class="form-label fw-bold">Passport number</label>
	                                            <input class="form-control" name="passport_number" <?php echo $horseDetailsRequired ? 'required' : ''; ?> value="<?php echo h($horseDetails['passport_number'] ?? ''); ?>" maxlength="32">
	                                        </div>
<div class="col-12 col-md-6">
<label class="form-label fw-bold">Owner name</label>
<input class="form-control" name="owner_name" maxlength="255" <?php echo $horseDetailsRequired ? 'required' : ''; ?> value="<?php echo h($horseDetails['owner_name'] ?? ''); ?>">
</div>
