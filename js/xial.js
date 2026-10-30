/**
 * This file is part of the IssueAnalysis plugin for ILIAS.
 *
 * Copyright (c) University of Cologne, CompetenceCenter E-Learning
 *
 * The plugin is licensed with the GPL-3.0,
 * see https://www.gnu.org/licenses/gpl-3.0.en.html
 * You should have received a copy of said license along with the
 * source code, too.
 */

il.xial = il.xial || {};

/**
 * Copy the text of an element to the clipboard when the button is clicked
 *
 * @param {string} buttonId   Id of the UI button
 * @param {string} contentId  Id of the element with the text
 * @param {string} copiedText Button label after copying
 * @param {string} failedText Button label if copying is not possible
 */
il.xial.bindCopy = (buttonId, contentId, copiedText, failedText) => {
  const button = document.getElementById(buttonId);
  const content = document.getElementById(contentId);
  if (!button || !content) {
    return;
  }

  const label = button.textContent;
  const showFeedback = (text) => {
    button.textContent = text;
    window.setTimeout(() => {
      button.textContent = label;
    }, 2000);
  };

  button.addEventListener('click', (event) => {
    event.preventDefault();
    // The clipboard API is only available in secure contexts (HTTPS)
    if (!window.navigator.clipboard) {
      showFeedback(failedText);
      return;
    }
    window.navigator.clipboard.writeText(content.textContent).then(
      () => showFeedback(copiedText),
      () => showFeedback(failedText),
    );
  });
};
