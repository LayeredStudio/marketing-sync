
// Send event for current authenticated user
// @returns: (bool) event being queued

function marketingSyncSendEvent(eventName, eventData) {
	var data = new FormData;
	data.append('_wpnonce', MarketingSyncEventsApi.nonce);
	data.append('eventName', eventName);
	for (key in eventData) {
		data.append('eventData[' + key + ']', eventData[key]);
	}

	return navigator.sendBeacon(MarketingSyncEventsApi.apiUrl, data);

	/* still testing
	return fetch(MarketingSyncEventsApi.apiUrl, {
		method:		'POST',
		headers:	{
			'Content-Type':	'application/json',
			'X-WP-Nonce':	MarketingSyncEventsApi.nonce
		},
		body:		JSON.stringify({
			eventName:		eventName,
			eventData:		eventData
		})
	}).then(function(response) {
		if (response.ok) {
			return response.json();
		} else if (response.headers.get('Content-Type').includes('application/json')) {
			return response.json().then(function(json) {
				throw json;
			});
		} else {
			throw new Error(response.statusText);
		}
	});
	*/
}

/*
Example

marketingSyncSendEvent('Test Event', {test: 'data'})
	.then(eventSentStatus => {
		console.log(eventSentStatus);
	})
	.catch(err => {
		if (err instanceof Error) {
			console.error('Network or server error:', err);
		} else {
			console.warn('Event recording failed because:', err.message);
		}
	});

*/
