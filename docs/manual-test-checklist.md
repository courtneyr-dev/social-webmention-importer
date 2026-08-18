# Manual test checklist

Run against staging with the Webmention plugin active before each release.

## Dependency

- [ ] Deactivate Webmention → admin notice appears, Tools screen absent.
- [ ] Reactivate Webmention → importer returns.

## Step 1 — target and URLs

- [ ] Tools → Social Webmention Importer loads for an administrator.
- [ ] A user without `moderate_comments` gets a 403 (test with an Author account).
- [ ] Post search filters the select; choosing a post shows its canonical URL.
- [ ] With JavaScript disabled, the newest-posts select still works end to end.
- [ ] "Import social responses" row action on a published post preselects it.
- [ ] Pasting 26+ URLs previews 25 and warns about the rest.
- [ ] Blank lines and surrounding whitespace are tolerated.

## Step 2 — preview

- [ ] A tweet that links to the article shows "verified Webmention".
- [ ] A tweet that doesn't shows "curated response only" and requires the
      confirmation checkbox before it will import.
- [ ] The known tweet case extracts the real name (not "X") and the
      profile-image avatar.
- [ ] A media tweet proposes no avatar.
- [ ] A LinkedIn authwall URL degrades to editable fields with a warning.
- [ ] An unsafe URL (e.g. `http://127.0.0.1/x`) errors per-row, batch continues.
- [ ] Editing a name and importing records it as reviewer-locked.
- [ ] "Use this identity for future imports" pre-fills the next preview of the
      same handle.
- [ ] Duplicate URL within the batch is flagged; existing source shows "will
      update".

## Step 3 — report and display

- [ ] Every URL gets exactly one result row; failures name the reason.
- [ ] Successes link to the comment editor and the public comment anchor.
- [ ] Imported comments are pending; approving one publishes it.
- [ ] Approved curated response shows author name, avatar, X badge (from the
      author profile URL), and the "Originally posted on X" label linking to
      the tweet — with JavaScript disabled too.
- [ ] LinkedIn curated response shows the LinkedIn badge (plugin CSS).
- [ ] Verified import renders exactly like an organic webmention comment.
- [ ] Re-importing the same tweet (either domain) updates, never duplicates.

## Comment row actions

- [ ] "Refresh social metadata" updates parser fields, keeps locked edits.
- [ ] Refreshing a deleted source sets the comment to pending and emails the admin.
- [ ] "Edit import details" reopens the importer with post + URL prefilled.

## Accessibility (step 1 and 2 screens)

- [ ] Tab order reaches every control; focus is visible throughout.
- [ ] Every input has a label; required fields are announced (screen-reader text).
- [ ] Warnings are associated with their card (aria-describedby) and errors
      use role="alert".
- [ ] The results table has a caption and column headers.
- [ ] Media Library picker is reachable by keyboard.

## Hygiene

- [ ] `composer lint` and `composer test` pass.
- [ ] No Webmention plugin or theme files modified (`git -C <deploy-repo> status`).
- [ ] Uninstall removes options only; imported comments survive.
