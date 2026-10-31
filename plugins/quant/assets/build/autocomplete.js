(()=>{"use strict";function e(e){let t;try{t=JSON.parse(e.getAttribute("data-quant-autocomplete"))}catch(e){return}const a=window["@algolia/autocomplete-js"];if(!t.index||!a||"undefined"==typeof algoliasearch)return;const{autocomplete:n,getAlgoliaResults:o}=a,i=algoliasearch(t.index.application_id,t.index.read_key);n({container:e,placeholder:t.placeholder||"Search",detachedMediaQuery:"none",navigator:{navigate({itemUrl:e}){window.location.assign(e)},navigateNewTab({itemUrl:e}){const t=window.open(e,"_blank","noopener");t&&t.focus()},navigateNewWindow({itemUrl:e}){window.open(e,"_blank","noopener")}},onSubmit({state:e}){var a;window.location.href=(a=e.query,`${t.searchPath}?${t.queryParam||"keys"}=${encodeURIComponent(a)}`)},getSources:()=>[{sourceId:t.index.index,getItemUrl:({item:e})=>e.url,getItems:({query:e})=>o({searchClient:i,queries:[{indexName:t.index.index,query:e,params:{filters:t.filters||"",hitsPerPage:8}}]}),onSelect({item:e}){window.location.assign(e.url)},templates:{item:({item:e,html:a})=>a`<div>
								<h6 class="quant-autocomplete__title">
									${e.title}
								</h6>
								${t.showSummary&&e.summary?a`<p
											class="quant-autocomplete__summary"
									  >
											${e.summary}
									  </p>`:""}
							</div>`,noResults:()=>"No results."}}]})}function t(){document.querySelectorAll("[data-quant-autocomplete]").forEach(e)}"loading"===document.readyState?document.addEventListener("DOMContentLoaded",t):t()})();