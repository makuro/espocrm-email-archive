define('archive:views/admin/email-archive', ['views/settings/record/edit'], (Dep) => {

    return class extends Dep {

        layoutName = 'emailArchive'
        saveAndContinueEditingAction = false
    };
});
