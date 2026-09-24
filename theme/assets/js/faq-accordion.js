const initializeFaqAccordions = () => {
	const items = document.querySelectorAll( '.vicunav-faq-accordion__item' );

	items.forEach( ( item, index ) => {
		const toggle = item.querySelector( '.vicunav-faq-accordion__toggle' );
		const button = toggle?.matches( 'button' )
			? toggle
			: toggle?.querySelector( 'button' );
		const answer = item.querySelector( '.vicunav-faq-accordion__answer' );
		const heading = item.querySelector( '.wp-block-post-title' );

		if ( ! button || ! answer ) {
			return;
		}

		const answerId = `vicunav-faq-answer-${ index + 1 }`;
		const question = heading ? heading.textContent.trim() : '';

		answer.id = answerId;
		button.setAttribute( 'aria-controls', answerId );

		const updateState = ( expanded ) => {
			const action = expanded ? 'Ocultar respuesta' : 'Mostrar respuesta';

			button.setAttribute( 'aria-expanded', expanded.toString() );
			button.textContent = action;
			button.setAttribute(
				'aria-label',
				question ? `${ action }: ${ question }` : action
			);
			answer.hidden = ! expanded;
		};

		updateState( button.getAttribute( 'aria-expanded' ) === 'true' );

		button.addEventListener( 'click', () => {
			updateState( button.getAttribute( 'aria-expanded' ) !== 'true' );
		} );
	} );
};

if ( document.readyState === 'loading' ) {
	document.addEventListener( 'DOMContentLoaded', initializeFaqAccordions );
} else {
	initializeFaqAccordions();
}
